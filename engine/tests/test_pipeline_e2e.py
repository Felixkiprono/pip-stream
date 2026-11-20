"""End-to-end: replayed Telegram posts -> intake (ingestor + parser) -> risk -> executor
(router on paper + position tracker), each worker on its own Redis ACL user, real Postgres,
using the shared schema Laravel owns (database/schema/pipstream.sql).

Uses the PLACEHOLDER config in config/pipstream.yaml. Needs Postgres + redis-server."""
from __future__ import annotations

import asyncio
import json
import os
from pathlib import Path

import pytest
from redis.asyncio import Redis

from pipstream.common.db import create_pool, migrate
from pipstream.common.events import TradeIntent, Topic, utcnow
from pipstream.common.service import build_context, close_context
from pipstream.common.settings import Settings
from pipstream.apps import executor as executor_app
from pipstream.apps import intake as intake_app
from pipstream.apps import risk as risk_app
from pipstream.devtools.seed import seed

from .conftest import wait_for

ROOT = Path(__file__).resolve().parents[1]

POSTS = [
    {"tg_chat_id": -1001000000001, "message_id": 101, "text": "Good morning traders! Big NFP week ahead"},
    {"tg_chat_id": -1001000000001, "message_id": 102, "text": "XAUUSD BUY 2348-2350 SL 2340 TP 2360 / 2375"},
    {"tg_chat_id": -1001000000002, "message_id": 201, "text": "EURUSD SELL NOW @ 1.0850\nSL: 1.0880\nTP1: 1.0820\nTP2: 1.0790"},
    {"tg_chat_id": -1001000000002, "message_id": 202, "text": "USDCHF buy 0.8820 sl 0.8790 tp 0.8860"},
    {"tg_chat_id": -1001000000003, "message_id": 301, "text": "V75 RISE 5 ticks stake $2"},
    {"tg_chat_id": -1001000000002, "message_id": 201, "text": "EURUSD SELL NOW @ 1.0850\nSL: 1.0880\nTP1: 1.0820\nTP2: 1.0790"},  # duplicate delivery
    {"tg_chat_id": -1001000000001, "message_id": 104, "text": "Move SL to entry", "reply_to_id": 102, "delay_ms": 2500},
    {"tg_chat_id": -1001000000002, "message_id": 203, "text": "close half", "reply_to_id": 201, "delay_ms": 300},
]


@pytest.fixture
async def stack(acl_redis, test_db, keys, tmp_path):
    for k in [k for k in os.environ if k.startswith(("PS_MT5_", "PS_DERIV_"))]:
        os.environ.pop(k)
    replay = tmp_path / "posts.jsonl"
    replay.write_text("\n".join(json.dumps({"delay_ms": 150, **p}) for p in POSTS))
    pool = await create_pool(test_db)
    await migrate(pool)
    counts = await seed(pool, str(ROOT / "config/pipstream.yaml"), keys["executor_public_key"])
    assert counts["broker_accounts_disabled"] == 2  # MT5 + Deriv have no env creds in tests

    admin = Redis.from_url(acl_redis["url"]("admin"), decode_responses=True)
    await admin.flushall()

    def settings(svc, **kw):
        return Settings(redis_url=acl_redis["url"](svc), database_url=test_db, router_snapshot_interval_s=1,
                        ingestor_source="replay", ingestor_replay_file=str(replay), **keys, **kw)

    ctxs, tasks = [], []

    async def start(name, main):
        c = await build_context(name, settings(name))
        ctxs.append(c)
        tasks.append(asyncio.create_task(main(c), name=name))
        return c

    await start("risk", risk_app.main)
    await start("executor", executor_app.main)
    await wait_for(lambda: admin.exists("ps:acct:ba_paper:info"))  # router snapshot ready
    await start("intake", lambda c: intake_app.main(c, http=False))
    yield {"db": pool, "redis": admin, "ctxs": ctxs, "keys": keys}
    for c in ctxs:
        c.stop.set()
        c.bus.stop()
    await asyncio.wait(tasks, timeout=5)
    for t in tasks:
        if t.done() and not t.cancelled() and t.exception():
            raise t.exception()
        t.cancel()
    for c in ctxs:
        await close_context(c)
    await pool.close()
    await admin.aclose()


async def test_signal_to_paper_trades(stack):
    db = stack["db"]

    async def done():
        return await db.fetchval("select count(*) from orders where action='close'") >= 1 and \
            await db.fetchval("select count(*) from orders where action='modify'") >= 2

    await wait_for(done, timeout=20)
    await asyncio.sleep(0.5)

    # noise rejected, three channels parsed; the duplicate post was deduped by the ingestor
    sig = {r["message_id"]: r for r in await db.fetch("select * from signals")}
    assert sig[101]["status"] == "rejected" and sig[101]["reason"] == "noise_prefilter"
    assert sig[102]["status"] == "parsed" and sig[301]["kind"] == "options"
    assert await db.fetchval("select count(*) from signals where message_id=201") == 1

    # gold: 1% of $10k, SL 9.00 away -> 0.11 lots split over 2 TPs (0.06 + 0.05)
    gold = await db.fetch("select * from positions where source_message_id=102 order by tp_index")
    assert [float(p["volume"]) for p in gold] == [0.06, 0.05]
    assert [float(p["tp"]) for p in gold] == [2360, 2375]
    # "Move SL to entry" (reply to 102) moved SL on exactly those tickets to the fill price
    assert all(float(p["sl"]) == float(p["open_price"]) == 2349 for p in gold)

    # EURUSD fixed 0.1 lots split 0.05/0.05; "close half" closed the TP1 leg
    eu = await db.fetch("select * from positions where source_message_id=201 order by tp_index")
    assert [(p["tp_index"], p["status"]) for p in eu] == [(1, "closed"), (2, "open")]

    # USDCHF denied by the rule filter -> no order
    assert await db.fetchval("select count(*) from orders where symbol='USDCHF'") == 0

    # options went to paper with the rule's stake ($1), not the post's $2
    opt = await db.fetchrow("select * from orders where symbol='R_75'")
    assert opt["status"] == "executed" and float(opt["volume"]) == 1.0

    # shadow rule recorded "would have traded" without any order on the MT5 account
    assert await db.fetchval("select count(*) from shadow_trades where copy_rule_id='rule_gold_shadow'") == 1
    assert await db.fetchval("select count(*) from orders where broker_account_id='ba_mt5_demo'") == 0

    # each intent executed exactly once and latency was measured from the post time
    assert await db.fetchval("select count(*) from orders o join trade_intents i on i.id=o.intent_id") == \
        await db.fetchval("select count(*) from trade_intents")
    lat = await db.fetchval("select max(latency_ms) from orders where action='open'")
    assert lat is not None and lat < 5000


async def test_forged_and_replayed_intents_are_refused(stack):
    db, admin = stack["db"], stack["redis"]
    await wait_for(lambda: db.fetchval("select count(*) from orders where action='close'"), timeout=20)
    await asyncio.sleep(1)  # let the replay finish
    before = await db.fetchval("select count(*) from orders")
    ev_tpl = {"event_id": "evt_forged", "topic": "trade.intent", "trace_id": "tr_forged",
              "occurred_at": utcnow().isoformat(), "producer": "risk"}
    forged = TradeIntent(idempotency_key="forged:1", user_id="usr_owner", broker_account_id="ba_paper",
                         copy_rule_id="rule_fx_paper", connector="paper", mode="paper", action="open",
                         signal_id="sig_x", channel_id="ch_fx_demo", source_message_id=1, symbol="EURUSD",
                         side="buy", volume=0.5, ref_price=1.08, expires_at=utcnow(), signature="AAAA")
    # an attacker with full Redis access (admin) injects an unsigned intent
    await admin.xadd("ps:stream:trade.intent", {"e": json.dumps({**ev_tpl, "payload": forged.model_dump(mode="json")})})
    # and replays a genuinely signed intent that was already executed
    real = await db.fetchval("select payload from trade_intents limit 1")
    await admin.xadd("ps:stream:trade.intent", {"e": json.dumps({**ev_tpl, "event_id": "evt_replay", "payload": real})})
    await wait_for(lambda: db.fetchval("select count(*) from audit_log where action='intent_bad_signature'"))
    await asyncio.sleep(1)
    assert await db.fetchval("select count(*) from orders") == before
