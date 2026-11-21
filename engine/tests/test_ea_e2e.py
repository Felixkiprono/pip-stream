"""End-to-end into MetaTrader: replayed Telegram posts -> intake -> risk -> executor -> EA bridge,
with a stand-in EA on the web app's Redis user (what Laravel's /ea/v1/sync does for a real terminal).
Covers: a market signal sized and sent to the terminal, the split into TP legs, "move SL to entry"
and "close half" as follow-ups, and how long a post takes to become a filled order."""
from __future__ import annotations

import asyncio
import json
import os
import time
from pathlib import Path

import pytest
from redis.asyncio import Redis

from pipstream.apps import executor as executor_app
from pipstream.apps import intake as intake_app
from pipstream.apps import risk as risk_app
from pipstream.common.db import create_pool, migrate
from pipstream.common.service import build_context, close_context
from pipstream.common.settings import Settings
from pipstream.devtools.seed import seed

from .conftest import wait_for

ROOT = Path(__file__).resolve().parents[1]
FX = -1001000000002
POSTS = [
    {"tg_chat_id": FX, "message_id": 501, "text": "EURUSD SELL NOW @ 1.0850\nSL: 1.0880\nTP1: 1.0820\nTP2: 1.0790"},
    {"tg_chat_id": FX, "message_id": 502, "text": "Move SL to entry", "reply_to_id": 501, "delay_ms": 2500},
    {"tg_chat_id": FX, "message_id": 503, "text": "close half", "reply_to_id": 501, "delay_ms": 1500},
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
    # the customer linked MetaTrader with the EA and pointed the FX channel at it (live, demo account)
    await pool.execute("insert into connectors (name, kind) values ('ea_bridge', 'cfd') on conflict do nothing")
    await pool.execute("insert into broker_accounts (id, user_id, connector, label) values ('ba_ea', 'usr_owner', 'ea_bridge', 'MT5 EA')")
    await pool.execute("update copy_rules set broker_account_id='ba_ea', mode='live' where id='rule_fx_paper'")

    admin = Redis.from_url(acl_redis["url"]("admin"), decode_responses=True)
    await admin.flushall()
    web = Redis.from_url(acl_redis["url"]("laravel"), decode_responses=True)
    state = {"balance": 10000, "equity": 10000, "currency": "USD", "trade_allowed": True, "broker_connected": True,
             "demo": True, "positions": []}
    await web.set("ps:eares:ba_ea:state", json.dumps(state))
    await web.set("ps:eares:ba_ea:specs", json.dumps({"EURUSDm": {
        "point": 0.00001, "tick_size": 0.00001, "tick_value": 1, "contract_size": 100000,
        "volume_min": 0.01, "volume_max": 100, "volume_step": 0.01}}))
    cmds: list[dict] = []
    tickets = iter(range(7001, 7100))

    async def ea():  # the terminal: polls every 100 ms, fills at the signal price
        done = set()
        while True:
            await web.set("ps:eares:ba_ea:seen", str(time.time()))
            for _id, f in reversed(await web.xrevrange("ps:acct:ba_ea:ea:cmd", "+", "-", count=50)):
                c = json.loads(f["c"])
                if c["id"] in done:
                    continue
                done.add(c["id"])
                c["_at"] = time.time()
                cmds.append(c)
                ans = {"ok": True, "price": 1.0850, "volume": c.get("volume")}
                if c["action"] == "open":
                    ans["ticket"] = str(next(tickets))
                else:
                    ans["ticket"] = c.get("ticket")
                await web.set(f"ps:eares:ba_ea:r:{c['id']}", json.dumps(ans))
            await asyncio.sleep(0.1)

    def settings(svc):
        return Settings(redis_url=acl_redis["url"](svc), database_url=test_db, router_snapshot_interval_s=1,
                        ingestor_source="replay", ingestor_replay_file=str(replay), **keys)

    ctxs, tasks = [], [asyncio.create_task(ea(), name="ea")]

    async def start(name, main):
        c = await build_context(name, settings(name))
        ctxs.append(c)
        tasks.append(asyncio.create_task(main(c), name=name))

    await start("risk", risk_app.main)
    await start("executor", executor_app.main)
    await wait_for(lambda: admin.exists("ps:acct:ba_ea:info"), timeout=10)
    await start("intake", lambda c: intake_app.main(c, http=False))
    yield {"db": pool, "cmds": cmds}
    for c in ctxs:
        c.stop.set()
        c.bus.stop()
    tasks[0].cancel()
    await asyncio.wait(tasks[1:], timeout=5)
    for t in tasks[1:]:
        if t.done() and not t.cancelled() and t.exception():
            raise t.exception()
        t.cancel()
    for c in ctxs:
        await close_context(c)
    await pool.close()
    await admin.aclose()
    await web.aclose()


async def test_telegram_post_becomes_metatrader_orders(stack):
    db, cmds = stack["db"], stack["cmds"]
    await wait_for(lambda: db.fetchval("select count(*) from orders where broker_account_id='ba_ea' and action='close'"), timeout=25)
    await asyncio.sleep(0.5)

    opens = [c for c in cmds if c["action"] == "open"]
    # fixed 0.1 lots split over the two TPs, on the broker's own symbol name, SL/TP as posted
    assert [(c["symbol"], c["side"], c["volume"], c["sl"], c["tp"]) for c in opens] == [
        ("EURUSDm", "sell", 0.05, 1.088, 1.082), ("EURUSDm", "sell", 0.05, 1.088, 1.079)]
    # "Move SL to entry" -> both tickets modified to the fill price; "close half" -> the TP1 leg closed
    assert sorted(c["sl"] for c in cmds if c["action"] == "modify") == [1.085, 1.085]
    closes = [c for c in cmds if c["action"] == "close"]
    assert len(closes) == 1 and closes[0]["ticket"] == opens[0].get("ticket", closes[0]["ticket"])
    legs = await db.fetch("select tp_index, status from positions where broker_account_id='ba_ea' order by tp_index")
    assert [(r["tp_index"], r["status"]) for r in legs] == [(1, "closed"), (2, "open")]
    # nothing went to the practice account, and each order ran once
    assert await db.fetchval("select count(*) from orders where broker_account_id='ba_paper' and symbol='EURUSD'") == 0
    lat = await db.fetchval("select max(latency_ms) from orders where broker_account_id='ba_ea' and action='open'")
    print(f"\nE2E: post -> filled in MetaTrader, worst leg {lat} ms (stand-in EA polls every 100 ms)")
    assert lat is not None and lat < 5000
