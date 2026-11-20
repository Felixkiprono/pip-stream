"""Common runtime for every worker service: settings, logging, Redis, Postgres, bus,
heartbeat (used by the container healthcheck) and graceful shutdown."""
from __future__ import annotations

import asyncio
import contextlib
import logging
import signal
import socket
import time
from collections.abc import Awaitable, Callable
from dataclasses import dataclass, field

import asyncpg
from redis.asyncio import Redis

from .bus import Bus
from .db import create_pool
from .log import setup_logging
from .settings import Settings


@dataclass
class Context:
    name: str
    settings: Settings
    redis: Redis
    bus: Bus
    db: asyncpg.Pool | None
    log: logging.Logger
    stop: asyncio.Event = field(default_factory=asyncio.Event)
    consumer: str = field(default_factory=lambda: f"{socket.gethostname()}")

    def health_key(self) -> str:
        return f"{self.settings.stream_prefix}:health:{self.name}"


async def build_context(name: str, settings: Settings, *, need_db: bool = True) -> Context:
    log = setup_logging(name, settings.log_level)
    redis = Redis.from_url(settings.redis_url, decode_responses=True)
    db = await create_pool(settings.database_url) if need_db else None
    bus = Bus(redis, name, prefix=settings.stream_prefix, maxlen=settings.stream_maxlen)
    return Context(name=name, settings=settings, redis=redis, bus=bus, db=db, log=log)


async def heartbeat(c: Context, every_s: float = 5.0) -> None:
    while not c.stop.is_set():
        with contextlib.suppress(Exception):
            await c.redis.set(c.health_key(), str(time.time()), ex=30)
        with contextlib.suppress(asyncio.TimeoutError):
            await asyncio.wait_for(c.stop.wait(), every_s)


async def close_context(c: Context) -> None:
    c.bus.stop()
    if c.db:
        await c.db.close()
    await c.redis.aclose()


def run(name: str, main: Callable[[Context], Awaitable[None]], *, need_db: bool = True) -> None:
    async def _amain():
        settings = Settings.from_env(service_name=name)
        c = await build_context(name, settings, need_db=need_db)
        loop = asyncio.get_running_loop()
        for sig in (signal.SIGINT, signal.SIGTERM):
            with contextlib.suppress(NotImplementedError):
                loop.add_signal_handler(sig, lambda: (c.stop.set(), c.bus.stop()))
        hb = asyncio.create_task(heartbeat(c))
        c.log.info("starting")
        try:
            await main(c)
        finally:
            c.stop.set()
            hb.cancel()
            await close_context(c)
            c.log.info("stopped")

    asyncio.run(_amain())
