"""Notifications: fans trade / risk events out to each user's preferred channels.
signal.rejected goes to the operator review queue (log) rather than to users."""
from __future__ import annotations

from ...common.events import Event, Topic
from ...common.log import ctx
from ...common.service import Context
from .providers.base import get as get_provider
from .templates import render

USER_EVENTS = {Topic.TRADE_EXECUTED, Topic.TRADE_FAILED, Topic.TRADE_CLOSED, Topic.RISK_LIMIT_HIT}


class Notifier:
    def __init__(self, c: Context):
        self.c = c
        self._providers: dict = {}

    def provider(self, name: str):
        if name not in self._providers:
            cls = get_provider(name)
            self._providers[name] = cls(self.c.settings) if cls else None
        return self._providers[name]

    async def handle(self, ev: Event) -> None:
        if ev.topic == Topic.SIGNAL_REJECTED:
            p = ev.payload
            self.c.log.info("review queue: signal rejected",
                            extra=ctx(trace_id=ev.trace_id, channel_id=p["channel_id"], reason=p["reason"]))
            return
        if ev.topic not in USER_EVENTS:
            return
        data = dict(ev.payload)
        prefs = await self.c.db.fetch("select channel, address from notification_prefs where user_id=$1 "
                                      "and $2 = any(events)", data["user_id"], str(ev.topic))
        subject, body = render(str(ev.topic), data)
        for pref in prefs:
            prov = self.provider(pref["channel"])
            if prov is None:
                continue
            try:
                await prov.send(pref["address"], subject, body)
            except Exception:
                self.c.log.exception("notification failed", extra=ctx(channel=pref["channel"], trace_id=ev.trace_id))


async def main(c: Context) -> None:
    n = Notifier(c)
    await c.bus.subscribe([Topic.TRADE_EXECUTED, Topic.TRADE_FAILED, Topic.TRADE_CLOSED, Topic.RISK_LIMIT_HIT,
                           Topic.SIGNAL_REJECTED], n.handle, consumer=c.consumer)
