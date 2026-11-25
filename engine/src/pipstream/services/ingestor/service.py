"""Telegram Ingestor: holds Telegram sessions, listens to channels, emits raw posts.

- No inbound ports. Outbound to Telegram only.
- Sessions are sharded across ingestor replicas (rendezvous hashing) and protected by a
  Redis lease so one session is never connected twice; if a replica dies its lease expires
  and standby readers take over the channels.
- Each channel is read ONCE (channel_readers: active + standby) and fanned out downstream.
- Dedupe key channel:message:edit_version for 24 h, and an "edit" whose text did not change
  (Telegram also reports reactions, view counts and re-sent updates as edits) is dropped.
"""
from __future__ import annotations

import asyncio
import contextlib
import hashlib

from ...common.events import Event, TgMessage, Topic
from ...common.ids import new_id
from ...common.log import ctx
from ...common.service import Context
from ...common.vault import Opener
from .lease import Lease
from .sharding import assign_readers, owner_shard
from .sources.base import RawPost, Source

DEDUPE_TTL_S = 24 * 3600


class IngestorService:
    def __init__(self, c: Context):
        self.c = c
        s = c.settings
        self.p = s.stream_prefix
        self.chat_to_channel: dict[int, str] = {}
        self.readers: list[dict] = []
        self.assignment: dict[str, str] = {}
        self.sources: dict[str, Source] = {}
        self.leases: dict[str, Lease] = {}
        self.replay = s.ingestor_source == "replay"

    # ------------------------------------------------------------------ config
    async def load_config(self) -> None:
        rows = await self.c.db.fetch("select id, tg_chat_id from channels where active")
        self.chat_to_channel = {r["tg_chat_id"]: r["id"] for r in rows}
        self.readers = [dict(r) for r in await self.c.db.fetch(
            "select r.channel_id, r.telegram_account_id, r.role from channel_readers r "
            "join channels c on c.id = r.channel_id join telegram_accounts t on t.id = r.telegram_account_id "
            "where c.active and t.status = 'active'")]
        await self.reassign()

    async def reassign(self) -> None:
        if self.replay:
            return
        live = set()
        for acc in {r["telegram_account_id"] for r in self.readers}:
            if await self.c.redis.exists(f"{self.p}:lease:tg:{acc}"):
                live.add(acc)
        new = assign_readers(self.readers, live)
        if new != self.assignment:
            self.c.log.info("channel readers assigned", extra=ctx(assignment=new))
        self.assignment = new

    async def on_config(self, ev: Event) -> None:
        if ev.payload.get("entity") in ("channel", "telegram_account", "channel_reader"):
            await self.load_config()
            await self.sync_sessions()

    # ------------------------------------------------------------------ sessions
    async def sync_sessions(self) -> None:
        if self.replay:
            return
        s = self.c.settings
        if not s.intake_private_key:
            self.c.log.error("PS_INTAKE_PRIVATE_KEY is not set; cannot open Telegram sessions")
            return
        if not s.ingestor_fake_telegram and not (s.telegram_api_id and s.telegram_api_hash):
            if not getattr(self, "_warned_api", False):
                self.c.log.error("PS_TELEGRAM_API_ID / PS_TELEGRAM_API_HASH are not set; not reading Telegram")
                self._warned_api = True
            return
        opener = Opener(s.intake_private_key)
        rows = await self.c.db.fetch("select id, session_ciphertext from telegram_accounts "
                                     "where status = 'active' and session_ciphertext is not null")
        mine = {r["id"]: r for r in rows if owner_shard(r["id"], s.ingestor_shards) == s.ingestor_shard}
        for acc_id in list(self.sources):
            if acc_id not in mine:
                await self._stop_session(acc_id)
        for acc_id, r in mine.items():
            if acc_id in self.sources:
                continue
            lease = Lease(self.c.redis, f"{self.p}:lease:tg:{acc_id}", self.c.consumer, s.ingestor_lease_ttl_ms)
            if not await lease.acquire():
                continue  # someone else holds it; we'll retry next tick
            if s.ingestor_fake_telegram:  # dev stand-in: posts come from `pipstream fake-post`
                from ...devtools.fake_telegram import FakeTelegramSource
                src = FakeTelegramSource(acc_id, self.c.redis, self.p)
            else:
                from .sources.telethon_source import TelethonSource
                src = TelethonSource(acc_id, opener.open(r["session_ciphertext"]), s.telegram_api_id,
                                     s.telegram_api_hash)
            try:
                await src.start(self.on_post)
            except Exception:
                self.c.log.exception("session start failed", extra=ctx(telegram_account_id=acc_id))
                await lease.release()
                continue
            self.sources[acc_id], self.leases[acc_id] = src, lease

    async def _stop_session(self, acc_id: str) -> None:
        src, lease = self.sources.pop(acc_id, None), self.leases.pop(acc_id, None)
        if src:
            await src.stop()
        if lease:
            await lease.release()

    async def maintain(self) -> None:
        s = self.c.settings
        every = max(s.ingestor_lease_ttl_ms / 3000, 1)
        while not self.c.stop.is_set():
            for acc_id, lease in list(self.leases.items()):
                if not await lease.renew():
                    self.c.log.error("lost session lease; disconnecting", extra=ctx(telegram_account_id=acc_id))
                    src = self.sources.pop(acc_id, None)
                    self.leases.pop(acc_id, None)
                    if src:
                        await src.stop()
            with contextlib.suppress(Exception):
                await self.sync_sessions()
                await self.reassign()
            with contextlib.suppress(asyncio.TimeoutError):
                await asyncio.wait_for(self.c.stop.wait(), every)
        for acc_id in list(self.sources):
            await self._stop_session(acc_id)

    async def confirm(self, account_id: str, text: str) -> None:
        src = self.sources.get(account_id)
        if src is not None and hasattr(src, "send_to_saved"):
            await src.send_to_saved(text)

    # ------------------------------------------------------------------ posts
    async def on_post(self, account_id: str, post: RawPost) -> None:
        channel_id = self.chat_to_channel.get(post.tg_chat_id)
        if channel_id is None:
            return  # not a channel we track
        if not self.replay and self.assignment.get(channel_id) != account_id:
            return  # another session is the reader for this channel
        version = 0
        tkey = f"{self.p}:editver:{post.tg_chat_id}:{post.message_id}:text"
        digest = hashlib.sha256(" ".join(post.text.split()).encode()).hexdigest()[:24]
        if post.is_edit:
            if await self.c.redis.get(tkey) == digest:
                return  # nothing in the text changed: not a new version of the signal
            vkey = f"{self.p}:editver:{post.tg_chat_id}:{post.message_id}"
            version = await self.c.redis.incr(vkey)
            await self.c.redis.expire(vkey, 7 * 24 * 3600)
        dkey = f"{self.p}:dedupe:{post.tg_chat_id}:{post.message_id}:{version}"
        if not await self.c.redis.set(dkey, "1", nx=True, ex=DEDUPE_TTL_S):
            return
        await self.c.redis.set(tkey, digest, ex=7 * 24 * 3600)
        msg = TgMessage(telegram_account_id=account_id, channel_id=channel_id, tg_chat_id=post.tg_chat_id,
                        message_id=post.message_id, reply_to_id=post.reply_to_id, edit_version=version,
                        text=post.text, has_media=post.has_media, posted_at=post.posted_at)
        topic = Topic.TG_MESSAGE_EDITED if post.is_edit else Topic.TG_MESSAGE_NEW
        ev = await self.c.bus.publish(topic, msg, trace_id=new_id("tr"))
        self.c.log.info("post ingested", extra=ctx(trace_id=ev.trace_id, channel_id=channel_id,
                                                   message_id=post.message_id, edit=version))


async def main(c: Context) -> None:
    svc = IngestorService(c)
    await svc.load_config()
    tasks = [c.bus.broadcast([Topic.CONFIG_CHANGED], svc.on_config)]
    if svc.replay:
        from .sources.replay_source import ReplaySource
        src = ReplaySource("replay", c.settings.ingestor_replay_file)
        await src.start(svc.on_post)
        tasks.append(src.wait())
    else:
        await svc.sync_sessions()
        tasks.append(svc.maintain())
    await asyncio.gather(*tasks)
