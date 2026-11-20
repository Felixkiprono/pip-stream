"""EA bridge connector: commands go out on the account's stream, answers come back as keys."""
import asyncio
import json
from datetime import timedelta

import pytest

from pipstream.common.events import TradeIntent, utcnow
from pipstream.services.execution_router.connectors import registry
from pipstream.services.execution_router.connectors.base import Connector, ConnectorError
from pipstream.services.execution_router.connectors.ea_bridge import MAGIC


def intent(**kw):
    d = dict(idempotency_key="k", user_id="u", broker_account_id="b1", copy_rule_id="r", connector="ea_bridge",
             mode="live", action="open", signal_id="s", channel_id="c", source_message_id=7, symbol="XAUUSDm",
             side="buy", volume=0.1, sl=2340.0, tp=2360.0, tp_index=1, expires_at=utcnow() + timedelta(seconds=5))
    d.update(kw)
    return TradeIntent(**d)


class FakeRedis:
    def __init__(self):
        self.kv, self.streams = {}, {}

    async def get(self, k):
        return self.kv.get(k)

    async def set(self, k, v, ex=None):
        self.kv[k] = v

    async def xadd(self, k, fields, maxlen=None, approximate=True):
        self.streams.setdefault(k, []).append(fields)
        return "1-0"


class FakeEA:
    """Plays the web app + EA: picks up commands and answers them."""

    def __init__(self, r: FakeRedis, answer=None):
        self.r, self.seen = r, 0
        self.answer = answer or (lambda c: {"ok": True, "ticket": "5001", "price": 2349.4, "volume": c.get("volume")})

    async def run(self):
        for _ in range(200):
            cmds = self.r.streams.get("ps:acct:b1:ea:cmd", [])
            for f in cmds[self.seen:]:
                c = json.loads(f["c"])
                self.r.kv[f"ps:eares:b1:r:{c['id']}"] = json.dumps(self.answer(c))
            self.seen = len(cmds)
            await asyncio.sleep(0.01)


def online(r, now=1000.0):
    r.kv["ps:eares:b1:seen"] = str(now)


def make(r, now=1000.0, **kw):
    clock = {"t": now}

    async def sleep(s):
        clock["t"] += s
        await asyncio.sleep(0.002)

    c = registry.create("ea_bridge", redis=r, account_id="b1", prefix="ps", sleep=sleep, clock=lambda: clock["t"], **kw)
    return c, clock


async def test_open_modify_close_round_trip():
    r = FakeRedis()
    online(r)
    c, _ = make(r)
    assert isinstance(c, Connector)
    await c.connect({})
    ea = asyncio.create_task(FakeEA(r).run())
    res = await c.open(intent())
    assert res.ok and res.ticket == "5001" and res.price == 2349.4
    cmd = json.loads(r.streams["ps:acct:b1:ea:cmd"][0]["c"])
    assert cmd["action"] == "open" and cmd["side"] == "buy" and cmd["symbol"] == "XAUUSDm" and cmd["sl"] == 2340.0
    assert cmd["expires_ms"] > 1000 * 1000

    assert (await c.modify("5001", sl=2349.4, tp=None)).ok
    m = json.loads(r.streams["ps:acct:b1:ea:cmd"][1]["c"])
    assert m == {**m, "action": "modify", "ticket": "5001", "sl": 2349.4, "tp": None}
    res = await c.close("5001", volume=0.05)
    assert res.ok and res.ticket == "5001"
    assert json.loads(r.streams["ps:acct:b1:ea:cmd"][2]["c"])["volume"] == 0.05
    ea.cancel()


async def test_broker_rejection_is_reported():
    r = FakeRedis()
    online(r)
    c, _ = make(r)
    ea = asyncio.create_task(FakeEA(r, lambda cmd: {"ok": False, "error": "not enough money", "retcode": 10019}).run())
    res = await c.open(intent())
    assert not res.ok and res.error == "not enough money"
    ea.cancel()


async def test_offline_terminal_fails_fast_and_sends_nothing():
    r = FakeRedis()
    c, _ = make(r)
    with pytest.raises(ConnectorError, match="never checked in"):
        await c.open(intent())
    online(r, now=900.0)  # 100 s ago
    with pytest.raises(ConnectorError, match="offline"):
        await c.open(intent())
    assert r.streams == {}


async def test_no_answer_times_out_and_retry_does_not_double():
    r = FakeRedis()
    online(r)
    c, clock = make(r, ttl_s=1.0)
    i = intent()
    with pytest.raises(ConnectorError, match="did not answer"):
        await c.open(i)
    assert len(r.streams["ps:acct:b1:ea:cmd"]) == 1


async def test_state_specs_positions():
    r = FakeRedis()
    online(r)
    r.kv["ps:eares:b1:state"] = json.dumps({"balance": 1000, "equity": 990.5, "currency": "USD", "positions": [
        {"ticket": "5001", "symbol": "XAUUSDm", "side": "buy", "volume": 0.1, "open_price": 2349.4, "sl": 2340,
         "tp": 2360, "profit": -9.5, "magic": MAGIC},
        {"ticket": "77", "symbol": "EURUSDm", "side": "sell", "volume": 1, "open_price": 1.1, "magic": 0}]})
    r.kv["ps:eares:b1:specs"] = json.dumps({
        "XAUUSDm": {"point": 0.001, "tick_size": 0.001, "tick_value": 0.1, "contract_size": 100,
                    "volume_min": 0.01, "volume_max": 200, "volume_step": 0.01},
        "BTCUSDm": {"point": 0.01, "tick_size": 0.01, "tick_value": 0.01}})
    c, _ = make(r)
    pos = await c.positions()
    assert [p.ticket for p in pos] == ["5001"]  # the customer's own trade is left alone
    info = await c.account_info(["XAUUSD"])
    assert info.balance == 1000 and list(info.symbols) == ["XAUUSDm"]
    assert (await c.health()).ok


async def test_only_the_executor_can_queue_ea_commands(acl_redis):
    """The web app reads commands and writes answers; it can never create a command."""
    from redis.asyncio import Redis
    from redis.exceptions import NoPermissionError

    ex = Redis.from_url(acl_redis["url"]("executor"), decode_responses=True)
    web = Redis.from_url(acl_redis["url"]("laravel"), decode_responses=True)
    key = "ps:acct:bk9:ea:cmd"
    await ex.xadd(key, {"c": "{}"}, maxlen=500, approximate=True)
    assert len(await web.xrevrange(key, "+", "-", count=5)) == 1
    with pytest.raises(NoPermissionError):
        await web.xadd(key, {"c": "{}"})
    await web.set("ps:eares:bk9:r:abc", "{}")
    assert await ex.get("ps:eares:bk9:r:abc") == "{}"
    with pytest.raises(NoPermissionError):
        await ex.set("ps:eares:bk9:r:abc", "x")  # the executor only reads answers
    await ex.aclose()
    await web.aclose()
