"""Duplicate-signal protection, end to end on the practice account:
- the same signal posted again as a new message opens nothing (duplicate_signal)
- an "edit" that changes nothing in the text is dropped by the ingestor
- an edit of a post that was already copied (and since closed) never opens it again
- a different signal still opens as usual"""
from __future__ import annotations

import asyncio
import json
import os
from pathlib import Path

import pytest
from redis.asyncio import Redis

from pipstream.apps import executor as executor_app
from pipstream.apps import intake as intake_app
from pipstream.apps import risk as risk_app
from pipstream.common.db import create_pool, migrate
from pipstream.common.events import ParsedSignal, SignalSource
from pipstream.common.service import build_context, close_context
from pipstream.common.settings import Settings
from pipstream.devtools.seed import seed
from pipstream.services.risk_engine.service import RiskEngine

from .conftest import wait_for

ROOT = Path(__file__).resolve().parents[1]
FX = -1001000000002
SIG = "EURUSD SELL NOW @ 1.0850\nSL: 1.0880\nTP1: 1.0820\nTP2: 1.0790"
POSTS = [
    {"tg_chat_id": FX, "message_id": 701, "text": SIG},
    {"tg_chat_id": FX, "message_id": 702, "text": SIG, "delay_ms": 800},                      # re-posted
    {"tg_chat_id": FX, "message_id": 701, "text": SIG.replace("\n", "  \n"), "edit": True},  # same text
    {"tg_chat_id": FX, "message_id": 703, "text": "close all", "reply_to_id": 701, "delay_ms": 1500},
    {"tg_chat_id": FX, "message_id": 701, "text": SIG.replace("1.0880", "1.0890"), "edit": True, "delay_ms": 1500},
    {"tg_chat_id": FX, "message_id": 704, "text": "GBPUSD SELL NOW @ 1.2700 SL 1.2750 TP 1.2650", "delay_ms": 800},
]


@pytest.fixture
async def stack(acl_redis, test_db, keys, tmp_path):
    for k in [k for k in os.environ if k.startswith(("PS_MT5_", "PS_DERIV_"))]:
        os.environ.pop(k)
    replay = tmp_path / "posts.jsonl"
    replay.write_text("\n".join(json.dumps({"delay_ms": 400, **p}) for p in POSTS))
    pool = await create_pool(test_db)
    await migrate(pool)
    await seed(pool, str(ROOT / "config/pipstream.yaml"), keys["executor_public_key"])
    admin = Redis.from_url(acl_redis["url"]("admin"), decode_responses=True)
    await admin.flushall()

    def settings(svc):
        return Settings(redis_url=acl_redis["url"](svc), database_url=test_db, router_snapshot_interval_s=1,
                        ingestor_source="replay", ingestor_replay_file=str(replay), **keys)

    ctxs, tasks = [], []

    async def start(name, main):
        c = await build_context(name, settings(name))
        ctxs.append(c)
        tasks.append(asyncio.create_task(main(c), name=name))

    await start("risk", risk_app.main)
    await start("executor", executor_app.main)
    await wait_for(lambda: admin.exists("ps:acct:ba_paper:info"), timeout=10)
    await start("intake", lambda c: intake_app.main(c, http=False))
    yield {"db": pool, "redis": admin}
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


async def test_repeats_and_edits_never_open_twice(stack):
    db, r = stack["db"], stack["redis"]
    await wait_for(lambda: db.fetchval("select count(*) from orders where symbol='GBPUSD'"), timeout=25)
    await asyncio.sleep(1)

    opens = await db.fetch("select symbol from orders where action='open' and status='executed' "
                           "and broker_account_id='ba_paper' and symbol in ('EURUSD','GBPUSD')")
    assert sorted(o["symbol"] for o in opens) == ["EURUSD", "EURUSD", "GBPUSD"]  # two TP legs + GBPUSD
    # the whitespace-only "edit" never became a new version; the real edit did
    assert [r_["edit_version"] for r_ in await db.fetch(
        "select edit_version from signals where message_id=701 order by edit_version")] == [0, 1]
    limits = [json.loads(f["e"])["payload"] for _id, f in await r.xrange("ps:stream:risk.limit_hit")]
    why = {(p["limit"], p["signal_id"]) for p in limits}
    assert any(lim == "duplicate_signal" for lim, _ in why)
    assert any(lim == "duplicate_edit" for lim, _ in why)


def _sig(**kw):
    d = dict(channel_id="ch", source=SignalSource(message_id=1), kind="open", symbol="XAUUSD", side="buy",
             sl=2340.0, tps=[2360.0, 2375.0], confidence=0.9, raw_text="x")
    d.update(kw)
    return ParsedSignal(**d)


def test_fingerprint_matches_only_the_same_levels():
    fp = RiskEngine.fingerprint
    assert fp(_sig()) == fp(_sig(source=SignalSource(message_id=2), signal_id="sig_other", raw_text="y"))
    assert fp(_sig()) != fp(_sig(side="sell"))
    assert fp(_sig()) != fp(_sig(sl=2341.0))
    assert fp(_sig()) != fp(_sig(tps=[2360.0]))
    assert fp(_sig()) != fp(_sig(channel_id="other"))
    assert fp(_sig(sl=2340)) == fp(_sig(sl=2340.0000001))  # float noise is not a new signal
