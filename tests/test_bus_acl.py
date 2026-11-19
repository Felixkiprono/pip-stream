"""Bus semantics and the Redis ACL generated from permissions.py (needs redis-server)."""
import asyncio

import pytest
import redis.exceptions
from redis.asyncio import Redis

from pipstream.common.bus import Bus, PublishNotAllowed
from pipstream.common.events import ConfigChanged, RiskLimitHit, Topic


def _limit():
    return RiskLimitHit(user_id="u", broker_account_id="b", copy_rule_id="r", signal_id="s", limit="x", detail="")


async def test_publish_consume_and_dead_letter(acl_redis):
    pub_r = Redis.from_url(acl_redis["url"]("risk_engine"), decode_responses=True)
    sub_r = Redis.from_url(acl_redis["url"]("notifications"), decode_responses=True)
    admin = Redis.from_url(acl_redis["url"]("admin"), decode_responses=True)
    await admin.delete("ps:stream:risk.limit_hit", "ps:stream:dlq")
    pub, sub = Bus(pub_r, "risk_engine"), Bus(sub_r, "notifications")
    seen, fails = [], {"n": 0}

    async def handler(ev):
        if ev.payload["limit"] == "boom":
            fails["n"] += 1
            raise RuntimeError("handler bug")
        seen.append(ev.payload["limit"])

    task = asyncio.create_task(sub.subscribe([Topic.RISK_LIMIT_HIT], handler, block_ms=100, claim_idle_ms=100,
                                             max_deliveries=3))
    await pub.publish(Topic.RISK_LIMIT_HIT, _limit())
    await pub.publish(Topic.RISK_LIMIT_HIT, _limit().model_copy(update={"limit": "boom"}))
    for _ in range(60):
        if seen and await admin.xlen("ps:stream:dlq"):
            break
        await asyncio.sleep(0.1)
    sub.stop()
    await task
    assert seen == ["x"]
    assert fails["n"] == 3 and await admin.xlen("ps:stream:dlq") == 1
    for r in (pub_r, sub_r, admin):
        await r.aclose()


async def test_client_side_publish_guard(acl_redis):
    r = Redis.from_url(acl_redis["url"]("parser"), decode_responses=True)
    with pytest.raises(PublishNotAllowed):
        await Bus(r, "parser").publish(Topic.RISK_LIMIT_HIT, _limit())
    await r.aclose()


async def test_redis_acl_blocks_foreign_publishers(acl_redis):
    """Even bypassing the Bus class, only the risk engine can XADD trade.intent."""
    parser = Redis.from_url(acl_redis["url"]("parser"), decode_responses=True)
    risk = Redis.from_url(acl_redis["url"]("risk_engine"), decode_responses=True)
    with pytest.raises(redis.exceptions.NoPermissionError):
        await parser.xadd("ps:stream:trade.intent", {"e": "{}"})
    with pytest.raises(redis.exceptions.NoPermissionError):
        await parser.delete("ps:stream:tg.message.new")       # consumers cannot wipe their input
    with pytest.raises(redis.exceptions.NoPermissionError):
        await parser.set("ps:killswitch:global", "0")           # nor flip kill switches
    assert await risk.xadd("ps:stream:trade.intent", {"e": "{}"})
    with pytest.raises(redis.exceptions.NoPermissionError):
        await risk.set("ps:killswitch:global", "0")            # read-only for the risk engine
    anon = Redis(port=acl_redis["port"])
    with pytest.raises(redis.exceptions.AuthenticationError):
        await anon.get("x")
    for r in (parser, risk, anon):
        await r.aclose()


async def test_broadcast_reaches_every_replica(acl_redis):
    api = Bus(Redis.from_url(acl_redis["url"]("core_api"), decode_responses=True), "core_api")
    a = Bus(Redis.from_url(acl_redis["url"]("parser"), decode_responses=True), "parser")
    b = Bus(Redis.from_url(acl_redis["url"]("parser"), decode_responses=True), "parser")
    got = {"a": 0, "b": 0}

    async def ha(ev):
        got["a"] += 1

    async def hb(ev):
        got["b"] += 1

    ta = asyncio.create_task(a.broadcast([Topic.CONFIG_CHANGED], ha, block_ms=100))
    tb = asyncio.create_task(b.broadcast([Topic.CONFIG_CHANGED], hb, block_ms=100))
    await asyncio.sleep(0.3)
    await api.publish(Topic.CONFIG_CHANGED, ConfigChanged(entity="channel"))
    for _ in range(30):
        if got["a"] and got["b"]:
            break
        await asyncio.sleep(0.1)
    a.stop(); b.stop()
    await asyncio.gather(ta, tb)
    assert got == {"a": 1, "b": 1}
