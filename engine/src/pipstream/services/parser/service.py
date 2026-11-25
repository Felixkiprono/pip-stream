"""Signal Parser service: tg.message.new|edited -> signal.parsed | signal.rejected.

Stateless apart from a cache of channel -> parser profile (reloaded on config.changed).
Writes the `signals` table (one row per channel/message/edit_version) so every decision is
traceable; re-deliveries re-publish the stored signal with the same signal_id, which keeps
downstream idempotency keys stable.
"""
from __future__ import annotations

import asyncio

from datetime import datetime, timezone

from ...common.events import Event, ParsedSignal, SelfTest, SignalRejected, SignalSource, TgMessage, Topic
from ...common.log import ctx
from ...common.service import Context, run
from . import selftest
from .engine import ParserProfile, SignalParser, SymbolCatalog


class ParserService:
    def __init__(self, c: Context):
        self.on_result = None  # optional async hook(msg, signal, rejected), e.g. dev confirmations
        self.c = c
        self.parser = SignalParser(SymbolCatalog.load(c.settings.symbols_file))
        self.profiles: dict[str, ParserProfile] = {}

    async def load_profiles(self) -> None:
        rows = await self.c.db.fetch(
            "select c.id as channel_id, p.id, p.version, p.min_confidence, p.config "
            "from channels c left join parser_profiles p on p.id = c.parser_profile_id where c.active")
        profiles = {}
        for r in rows:
            if r["id"] is None:
                profiles[r["channel_id"]] = ParserProfile()
                continue
            cfg = dict(r["config"] or {})
            cfg.update(id=r["id"], version=r["version"], min_confidence=float(r["min_confidence"]))
            profiles[r["channel_id"]] = ParserProfile.model_validate(cfg)
        self.profiles = profiles
        self.c.log.info("parser profiles loaded", extra=ctx(channels=len(profiles)))

    async def on_config(self, ev: Event) -> None:
        if ev.payload.get("entity") in ("channel", "channel_reader", "parser_profile"):
            await self.load_profiles()

    async def handle(self, ev: Event) -> None:
        msg: TgMessage = ev.parse()
        profile = self.profiles.get(msg.channel_id)
        if profile is None:
            self.c.log.warning("message for unknown/inactive channel", extra=ctx(channel_id=msg.channel_id))
            return
        source = SignalSource(message_id=msg.message_id, reply_to_id=msg.reply_to_id,
                              edit_version=msg.edit_version, posted_at=msg.posted_at)
        if code := selftest.find_code(msg.text):
            await self._self_test(code, msg, source, profile, ev.trace_id)
            return
        if msg.has_media and not msg.text.strip():
            result_rej = SignalRejected(channel_id=msg.channel_id, source=source, reason="media_only",
                                        raw_text="")
            await self._emit_rejected(result_rej, ev.trace_id)
            return
        res = self.parser.parse(msg.text, channel_id=msg.channel_id, source=source, profile=profile)
        if res.signal:
            await self._emit_parsed(res.signal, ev.trace_id)
        else:
            await self._emit_rejected(res.rejected, ev.trace_id)
        if self.on_result:
            try:
                await self.on_result(msg, res.signal, res.rejected)
            except Exception:
                self.c.log.exception("on_result hook failed", extra=ctx(trace_id=ev.trace_id))

    async def _self_test(self, code: str, msg: TgMessage, source: SignalSource, profile: ParserProfile,
                         trace_id: str) -> None:
        """A post with a self-test code. It never reaches other customers' rules: it goes to the
        risk engine only for the customer who started the test, marked so nothing is placed
        unless they asked for it."""
        p = self.c.settings.stream_prefix
        meta = await selftest.load_meta(self.c.redis, p, code)
        log = ctx(trace_id=trace_id, channel_id=msg.channel_id, code=code)
        if meta is None:
            self.c.log.info("self-test code unknown or expired; post ignored", extra=log)
            return
        tid = meta["test_id"]
        arrived = {"channel_id": msg.channel_id, "message_id": msg.message_id, "edit_version": msg.edit_version,
                   "posted_at": msg.posted_at.isoformat() if msg.posted_at else None,
                   "received_at": datetime.now(timezone.utc).isoformat()}
        if msg.channel_id != meta["channel_id"]:
            await selftest.update(self.c.redis, p, tid, state="wrong_channel", arrived=arrived, done=True)
            self.c.log.info("self-test code posted in another channel", extra=log)
            return
        text = selftest.strip_code(msg.text)
        if not text:
            await selftest.update(self.c.redis, p, tid, state="received", arrived=arrived, done=True,
                                  parse={"status": "ping"})
            self.c.log.info("self-test ping received", extra=log)
            return
        res = self.parser.parse(text, channel_id=msg.channel_id, source=source, profile=profile)
        if res.signal is None:
            await selftest.update(self.c.redis, p, tid, state="received", arrived=arrived, done=True,
                                  parse={"status": "rejected", "reason": res.rejected.reason,
                                         "confidence": res.rejected.confidence, "issues": res.issues})
            return
        sig = res.signal.model_copy(update={"test": SelfTest(test_id=tid, user_id=meta["user_id"],
                                                             place=bool(meta.get("place")))})
        await selftest.update(self.c.redis, p, tid, state="received", arrived=arrived, done=False,
                              parse={"status": "parsed", "confidence": sig.confidence, "issues": res.issues,
                                     "signal": sig.model_dump(mode="json", by_alias=True, exclude={"test"})})
        self.c.log.info("self-test signal parsed", extra=log)
        if sig.test.place:  # a real (practice or broker) trade: stored like any signal so it shows in Trades
            await self._emit_parsed(sig, trace_id)
        else:
            await self.c.bus.publish(Topic.SIGNAL_PARSED, sig, trace_id=trace_id)

    async def _emit_parsed(self, sig: ParsedSignal, trace_id: str) -> None:
        row = await self.c.db.fetchrow(
            "insert into signals (id, channel_id, message_id, reply_to_id, edit_version, status, kind, "
            "symbol, side, confidence, payload, trace_id) values ($1,$2,$3,$4,$5,'parsed',$6,$7,$8,$9,$10,$11) "
            "on conflict (channel_id, message_id, edit_version) do update set channel_id = excluded.channel_id "
            "returning id, payload",
            sig.signal_id, sig.channel_id, sig.source.message_id, sig.source.reply_to_id,
            sig.source.edit_version, sig.kind, sig.symbol, sig.side, sig.confidence,
            sig.model_dump(mode="json", by_alias=True), trace_id)
        if row["id"] != sig.signal_id:  # re-delivery: keep the original signal id
            if row["payload"].get("kind") is None:  # previously rejected; nothing to republish
                return
            sig = ParsedSignal.model_validate(row["payload"])
        await self.c.bus.publish(Topic.SIGNAL_PARSED, sig, trace_id=trace_id)
        self.c.log.info("signal parsed", extra=ctx(trace_id=trace_id, signal_id=sig.signal_id,
                                                   kind=sig.kind, symbol=sig.symbol, conf=sig.confidence))

    async def _emit_rejected(self, rej: SignalRejected, trace_id: str) -> None:
        from ...common.ids import new_id
        await self.c.db.execute(
            "insert into signals (id, channel_id, message_id, reply_to_id, edit_version, status, "
            "confidence, reason, payload, trace_id) values ($1,$2,$3,$4,$5,'rejected',$6,$7,$8,$9) "
            "on conflict do nothing",
            new_id("sig"), rej.channel_id, rej.source.message_id, rej.source.reply_to_id,
            rej.source.edit_version, rej.confidence, rej.reason, rej.model_dump(mode="json"), trace_id)
        await self.c.bus.publish(Topic.SIGNAL_REJECTED, rej, trace_id=trace_id)


async def main(c: Context) -> None:
    svc = ParserService(c)
    await svc.load_profiles()
    await asyncio.gather(
        c.bus.subscribe([Topic.TG_MESSAGE_NEW, Topic.TG_MESSAGE_EDITED], svc.handle, consumer=c.consumer),
        c.bus.broadcast([Topic.CONFIG_CHANGED], svc.on_config),
    )


