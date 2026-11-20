"""Message bus on Redis Streams (MVP). Swap for NATS JetStream later behind the same API.

Semantics
- publish(): XADD to `ps:stream:<topic>`, capped with MAXLEN ~.
- subscribe(): consumer groups -> each event is processed by ONE replica of a service.
  At-least-once: handlers must be idempotent. Failures are retried (pending entries are
  reclaimed after `claim_idle_ms`) and dead-lettered to `ps:stream:dlq` after
  `max_deliveries`.
- broadcast(): plain XREAD from "$" -> EVERY replica sees the event (config.changed).
"""
from __future__ import annotations

import asyncio
import json
import logging
from collections.abc import Awaitable, Callable, Iterable

from pydantic import BaseModel
from redis.asyncio import Redis

from .events import TOPIC_PAYLOADS, Event, Topic
from .ids import new_id
from .log import ctx
from .permissions import SERVICES, stream_key

Handler = Callable[[Event], Awaitable[None]]
log = logging.getLogger("pipstream.bus")


class PublishNotAllowed(PermissionError):
    pass


class Bus:
    def __init__(self, redis: Redis, service: str, *, prefix: str = "ps", maxlen: int = 100_000,
                 enforce: bool = True):
        self.redis = redis
        self.service = service
        self.prefix = prefix
        self.maxlen = maxlen
        self.enforce = enforce and service in SERVICES
        self._stop = asyncio.Event()

    def key(self, topic: Topic | str) -> str:
        return stream_key(self.prefix, str(topic))

    # ------------------------------------------------------------ publish
    async def publish(self, topic: Topic, payload: BaseModel, *, trace_id: str | None = None) -> Event:
        if self.enforce and topic not in SERVICES[self.service].publish:
            raise PublishNotAllowed(f"{self.service} may not publish {topic}")
        expected = TOPIC_PAYLOADS[topic]
        if not isinstance(payload, expected):
            raise TypeError(f"{topic} expects {expected.__name__}, got {type(payload).__name__}")
        ev = Event(topic=topic, trace_id=trace_id or new_id("tr"), producer=self.service,
                   payload=payload.model_dump(mode="json", by_alias=True))
        await self.redis.xadd(self.key(topic), {"e": ev.model_dump_json()},
                              maxlen=self.maxlen, approximate=True)
        return ev

    # ------------------------------------------------------------ consume
    def stop(self) -> None:
        self._stop.set()

    async def _ensure_group(self, key: str, group: str) -> None:
        try:
            await self.redis.xgroup_create(key, group, id="0", mkstream=True)
        except Exception as e:  # BUSYGROUP = already exists
            if "BUSYGROUP" not in str(e):
                raise

    async def subscribe(self, topics: Iterable[Topic], handler: Handler, *, group: str | None = None,
                        consumer: str = "c1", concurrency: int = 16, block_ms: int = 1000,
                        claim_idle_ms: int = 5000, max_deliveries: int = 5, delete_after: bool = False) -> None:
        """delete_after: XDEL each entry once handled and never copy its body to the DLQ (for
        events that carry secrets, e.g. tg.login.request with a login code or 2FA password)."""
        group = group or self.service
        keys = [self.key(t) for t in topics]
        for k in keys:
            await self._ensure_group(k, group)
        sem = asyncio.Semaphore(concurrency)
        deliveries_key = f"{self.prefix}:deliveries:{self.service}"
        tasks: set[asyncio.Task] = set()

        async def process(key: str, msg_id: str, fields: dict) -> None:
            async with sem:
                raw = fields.get("e") or fields.get(b"e")
                try:
                    ev = Event.model_validate_json(raw)
                except Exception:
                    log.error("undecodable event dead-lettered", extra=ctx(stream=key, id=msg_id))
                    await self._dead_letter(key, msg_id, '{"redacted": true}' if delete_after else raw, "decode_error")
                    await self.redis.xack(key, group, msg_id)
                    if delete_after:
                        await self.redis.xdel(key, msg_id)
                    return
                try:
                    await handler(ev)
                except Exception as exc:
                    n = await self.redis.hincrby(deliveries_key, f"{key}|{msg_id}", 1)
                    log.exception("handler failed", extra=ctx(topic=ev.topic, trace_id=ev.trace_id,
                                                              attempt=n, event_id=ev.event_id))
                    if n >= max_deliveries:
                        await self._dead_letter(key, msg_id, '{"redacted": true}' if delete_after else raw, repr(exc))
                        await self.redis.xack(key, group, msg_id)
                        await self.redis.hdel(deliveries_key, f"{key}|{msg_id}")
                        if delete_after:
                            await self.redis.xdel(key, msg_id)
                    return
                await self.redis.xack(key, group, msg_id)
                if delete_after:
                    await self.redis.xdel(key, msg_id)

        # Entries this subscriber is handling or has queued. XAUTOCLAIM only looks at idle time,
        # so without this a handler slower than claim_idle_ms (e.g. a Telegram call that takes
        # 7 s) would be claimed back and run a second time while the first run is still going.
        inflight: set[tuple[str, str]] = set()

        def spawn(key, msg_id, fields):
            ident = (_s(key), _s(msg_id))
            if ident in inflight:
                return
            inflight.add(ident)
            t = asyncio.create_task(process(*ident, fields))
            tasks.add(t)
            t.add_done_callback(lambda _t: (tasks.discard(_t), inflight.discard(ident)))

        last_claim = 0.0
        loop = asyncio.get_running_loop()
        while not self._stop.is_set():
            # Reclaim entries another (dead) consumer left pending, or our own failures.
            if loop.time() - last_claim > claim_idle_ms / 1000:
                last_claim = loop.time()
                for k in keys:
                    res = await self.redis.xautoclaim(k, group, consumer, min_idle_time=claim_idle_ms,
                                                      start_id="0-0", count=100)
                    for msg_id, fields in (res[1] if res else []):
                        if fields:
                            spawn(k, msg_id, fields)
            resp = await self.redis.xreadgroup(group, consumer, {k: ">" for k in keys},
                                               count=concurrency, block=block_ms)
            for key, entries in resp or []:
                for msg_id, fields in entries:
                    spawn(key, msg_id, fields)
        if tasks:
            await asyncio.gather(*tasks, return_exceptions=True)

    async def broadcast(self, topics: Iterable[Topic], handler: Handler, *, block_ms: int = 1000) -> None:
        last = {self.key(t): "$" for t in topics}
        while not self._stop.is_set():
            resp = await self.redis.xread(last, block=block_ms, count=100)
            for key, entries in resp or []:
                for msg_id, fields in entries:
                    last[_s(key)] = _s(msg_id)
                    try:
                        await handler(Event.model_validate_json(fields.get("e") or fields.get(b"e")))
                    except Exception:
                        log.exception("broadcast handler failed")

    async def _dead_letter(self, key: str, msg_id: str, raw, reason: str) -> None:
        await self.redis.xadd(self.key("dlq"), {"stream": key, "id": msg_id, "reason": reason[:500],
                                                "e": raw or ""}, maxlen=self.maxlen, approximate=True)


def _s(v) -> str:
    return v.decode() if isinstance(v, bytes) else v


def dumps(obj) -> str:
    return json.dumps(obj, separators=(",", ":"), default=str)
