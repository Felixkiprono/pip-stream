"""Customer commands from the PipStream bot (user.command), end to end on the practice account:
- a rule with "ask me before each trade" holds a new signal and asks (risk.limit_hit awaiting_confirm)
- yes copies it; skip drops it; a yes after the hold expired says so (confirm_expired)
- close all closes every open trade, even while the customer has paused copying"""
from __future__ import annotations

import asyncio
import json
import os
from pathlib import Path

import pytest
from redis.asyncio import Redis

from pipstream.apps import executor as executor_app
from pipstream.apps import risk as risk_app
from pipstream.common.db import create_pool, migrate
from pipstream.common.events import EntrySpec, Event, ParsedSignal, SignalSource, Topic, UserCommand
from pipstream.common.service import build_context, close_context
from pipstream.common.settings import Settings
from pipstream.devtools.seed import seed

from .conftest import wait_for

ROOT = Path(__file__).resolve().parents[1]


@pytest.fixture
async def stack(acl_redis, test_db, keys):
    for k in [k for k in os.environ if k.startswith(("PS_MT5_", "PS_DERIV_"))]:
        os.environ.pop(k)
    pool = await create_pool(test_db)
    await migrate(pool)
    await seed(pool, str(ROOT / "config/pipstream.yaml"), keys["executor_public_key"])
    await pool.execute("update copy_rules set limits = limits || '{\"confirm_first\": true}' where id = 'rule_fx_paper'")
    admin = Redis.from_url(acl_redis["url"]("admin"), decode_responses=True)
    await admin.flushall()
    ctxs, tasks = [], []
    for name, main in (("risk", risk_app.main), ("executor", executor_app.main)):
        c = await build_context(name, Settings(redis_url=acl_redis["url"](name), database_url=test_db,
                                               router_snapshot_interval_s=1, risk_confirm_ttl_s=2, **keys))
        ctxs.append(c)
        tasks.append(asyncio.create_task(main(c), name=name))
    await wait_for(lambda: admin.exists("ps:acct:ba_paper:info"), timeout=10)
    await admin.set("ps:quote:EURUSD", "1.0850")
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


async def _publish(r: Redis, topic: Topic, payload, producer: str) -> None:
    ev = Event(topic=topic, trace_id="tr_test", producer=producer, payload=payload.model_dump(mode="json", by_alias=True))
    await r.xadd(f"ps:stream:{topic}", {"e": ev.model_dump_json()})


async def _signal(r: Redis, msg: int, symbol: str = "EURUSD") -> ParsedSignal:
    sig = ParsedSignal(channel_id="ch_fx_demo", source=SignalSource(message_id=msg), kind="open", symbol=symbol, side="sell",
                       entry=EntrySpec(type="market", price=1.0850), sl=1.0880, tps=[1.0820], confidence=0.95, raw_text="x")
    await _publish(r, Topic.SIGNAL_PARSED, sig, "intake")
    return sig


async def _limits(r: Redis) -> list[dict]:
    return [json.loads(f["e"])["payload"] for _id, f in await r.xrange("ps:stream:risk.limit_hit")]


async def _held(r: Redis, signal_id: str) -> str:
    async def find():
        return next((p["detail"] for p in await _limits(r) if p["limit"] == "awaiting_confirm" and p["signal_id"] == signal_id), None)
    await wait_for(find, timeout=10)
    return await find()


async def _cmd(r: Redis, action: str, pending_id: str | None = None, n: int = 0) -> None:
    await _publish(r, Topic.USER_COMMAND, UserCommand(command_id=f"cmd_{action}_{n}", user_id="usr_owner", action=action,
                                                     pending_id=pending_id), "laravel")


async def test_ask_first_then_close_all(stack):
    db, r = stack["db"], stack["redis"]
    opened = "select count(*) from orders where action='open' and status='executed' and symbol='EURUSD'"

    # held: nothing traded until the customer says yes
    s1 = await _signal(r, 801)
    pid = await _held(r, s1.signal_id)
    assert pid.startswith("pnd_")
    await asyncio.sleep(0.5)
    assert await db.fetchval(opened) == 0
    await _cmd(r, "approve_signal", pid)
    await wait_for(lambda: db.fetchval(opened), timeout=10)
    await _cmd(r, "approve_signal", pid, n=1)  # a second tap never trades twice
    await asyncio.sleep(1)
    assert await db.fetchval(opened) == 1

    # skipped: dropped, nothing traded
    s2 = await _signal(r, 802, "GBPUSD")
    pid2 = await _held(r, s2.signal_id)
    await _cmd(r, "reject_signal", pid2)
    await asyncio.sleep(0.8)
    assert {p["limit"] for p in await _limits(r) if p["signal_id"] == s2.signal_id} == {"awaiting_confirm", "confirm_rejected"}
    assert not await r.exists(f"ps:pending:{pid2}")

    # expired: the hold is gone after the TTL (2 s here); a late yes is told so
    s3 = await _signal(r, 803, "AUDUSD")
    pid3 = await _held(r, s3.signal_id)
    await asyncio.sleep(2.5)
    await _cmd(r, "approve_signal", pid3)

    async def expired():
        return any(p["limit"] == "confirm_expired" for p in await _limits(r))
    await wait_for(expired, timeout=10)
    assert await db.fetchval("select count(*) from orders where symbol='AUDUSD'") == 0

    # close all, while paused: closes still go through
    await wait_for(lambda: db.fetchval("select count(*) from positions where user_id='usr_owner' and status='open'"), timeout=10)
    await r.set("ps:killswitch:user:usr_owner", "1")
    await _cmd(r, "close_all")
    await wait_for(lambda: db.fetchval("select count(*) = 0 from positions where user_id='usr_owner' and status='open'"), timeout=15)
    closes = await db.fetch("select status, error from orders where action='close'")
    assert closes and all(c["status"] == "executed" for c in closes), closes
