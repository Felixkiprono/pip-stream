"""Channel self-test end to end: customer posts with a PS-XXXXXX code reach intake and risk,
the result lands in ps:chtest:<id>, and nothing is traded or stored as a signal unless the
customer asked for the trade to be placed."""
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
from pipstream.common.service import build_context, close_context
from pipstream.common.settings import Settings
from pipstream.devtools.seed import seed
from pipstream.services.parser.selftest import find_code, strip_code

from .conftest import wait_for

ROOT = Path(__file__).resolve().parents[1]
GOLD, FX = -1001000000001, -1001000000002

TESTS = {  # code -> (test_id, channel the customer is testing, place?)
    "ABCDEF": ("t_ping", "ch_gold_demo", False),
    "GHJKLM": ("t_gold", "ch_gold_demo", False),
    "NPQRST": ("t_wrong", "ch_gold_demo", False),
    "UVWXYZ": ("t_place", "ch_fx_demo", True),
    "234567": ("t_noise", "ch_fx_demo", False),
}
POSTS = [
    {"tg_chat_id": GOLD, "message_id": 501, "text": "PipStream test PS-ABCDEF"},
    {"tg_chat_id": GOLD, "message_id": 502, "text": "PipStream test PS-GHJKLM\nXAUUSD BUY 2348-2350 SL 2340 TP 2360 / 2375"},
    {"tg_chat_id": FX, "message_id": 601, "text": "PS-NPQRST EURUSD SELL NOW @ 1.0850 SL 1.0880 TP 1.0820"},
    {"tg_chat_id": FX, "message_id": 602, "text": "PS-UVWXYZ\nEURUSD SELL NOW @ 1.0850\nSL: 1.0880\nTP1: 1.0820"},
    {"tg_chat_id": FX, "message_id": 603, "text": "ps-234567 hello traders, big week ahead"},
    # an expired / unknown code: never traded, even though the rest is a valid signal
    {"tg_chat_id": GOLD, "message_id": 504, "text": "PS-ZZZZZZ XAUUSD BUY 2348-2350 SL 2340 TP 2360"},
]


def test_code_helpers():
    assert find_code("hi ps-abcdef there") == "ABCDEF"
    assert find_code("PS-ABCDE1") is None  # 1 is not in the alphabet
    assert find_code("XAUUSD BUY") is None
    assert strip_code("PipStream test PS-ABCDEF") == ""
    assert strip_code("PipStream test PS-ABCDEF\nXAUUSD BUY 2350 SL 2340") == "XAUUSD BUY 2350 SL 2340"


@pytest.fixture
async def stack(acl_redis, test_db, keys, tmp_path):
    for k in [k for k in os.environ if k.startswith(("PS_MT5_", "PS_DERIV_"))]:
        os.environ.pop(k)
    replay = tmp_path / "posts.jsonl"
    replay.write_text("\n".join(json.dumps({"delay_ms": 150, **p}) for p in POSTS))
    pool = await create_pool(test_db)
    await migrate(pool)
    await seed(pool, str(ROOT / "config/pipstream.yaml"), keys["executor_public_key"])
    admin = Redis.from_url(acl_redis["url"]("admin"), decode_responses=True)
    await admin.flushall()
    # what Laravel writes when the customer presses "Test this channel"
    laravel = Redis.from_url(acl_redis["url"]("laravel"), decode_responses=True)
    for code, (tid, ch, place) in TESTS.items():
        meta = {"test_id": tid, "user_id": "usr_owner", "channel_id": ch, "place": place}
        await laravel.set(f"ps:chtest:code:{code}", json.dumps(meta), ex=600)
        await laravel.set(f"ps:chtest:{tid}", json.dumps({**meta, "state": "waiting"}), ex=600)
    await laravel.aclose()

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
    await wait_for(lambda: admin.exists("ps:acct:ba_paper:info"))
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


async def test_self_test_posts(stack):
    db, r = stack["db"], stack["redis"]

    async def result(tid):
        raw = await r.get(f"ps:chtest:{tid}")
        return json.loads(raw) if raw else {}

    async def all_done():
        return all([(await result(t)).get("done") for t, _, _ in TESTS.values()]) and \
            await db.fetchval("select count(*) from orders") >= 1

    await wait_for(all_done, timeout=20)
    await asyncio.sleep(1)

    ping = await result("t_ping")
    assert ping["state"] == "received" and ping["parse"] == {"status": "ping"}
    assert ping["arrived"]["message_id"] == 501 and ping["arrived"]["posted_at"]

    gold = await result("t_gold")
    assert gold["parse"]["status"] == "parsed" and gold["parse"]["signal"]["symbol"] == "XAUUSD"
    assert gold["plan"]["status"] == "would_copy" and gold["plan"]["mode"] == "paper"
    assert [leg["volume"] for leg in gold["plan"]["legs"]] == [0.06, 0.05]  # same sizing as a real post

    assert (await result("t_wrong"))["state"] == "wrong_channel"
    noise = await result("t_noise")
    assert noise["parse"]["status"] == "rejected" and noise["parse"]["reason"]

    placed = await result("t_place")
    assert placed["plan"]["status"] == "placed" and placed["plan"]["signal_id"]

    # only the placed test became a signal and a trade; nothing else was stored or copied
    assert [r["message_id"] for r in await db.fetch("select message_id from signals")] == [602]
    orders = await db.fetch("select * from orders")
    assert {o["symbol"] for o in orders} == {"EURUSD"} and all(o["status"] == "executed" for o in orders)
    assert await db.fetchval("select count(*) from shadow_trades") == 0
