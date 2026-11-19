"""Redis lease so a Telegram session is never connected by two workers at once.
Telegram invalidates sessions used concurrently from two places, so this is critical."""
from __future__ import annotations

from redis.asyncio import Redis

_RENEW = """
if redis.call('get', KEYS[1]) == ARGV[1] then
  return redis.call('pexpire', KEYS[1], ARGV[2])
end
return 0
"""
_RELEASE = """
if redis.call('get', KEYS[1]) == ARGV[1] then
  return redis.call('del', KEYS[1])
end
return 0
"""


class Lease:
    def __init__(self, redis: Redis, key: str, owner: str, ttl_ms: int):
        self.redis, self.key, self.owner, self.ttl_ms = redis, key, owner, ttl_ms

    async def acquire(self) -> bool:
        return bool(await self.redis.set(self.key, self.owner, nx=True, px=self.ttl_ms))

    async def renew(self) -> bool:
        return bool(await self.redis.eval(_RENEW, 1, self.key, self.owner, self.ttl_ms))

    async def release(self) -> None:
        await self.redis.eval(_RELEASE, 1, self.key, self.owner)

    async def holder(self) -> str | None:
        return await self.redis.get(self.key)
