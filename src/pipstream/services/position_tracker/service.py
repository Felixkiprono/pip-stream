"""Position Tracker: owns the `positions` table, i.e. the link Telegram message -> tickets.

- trade.executed(open)   -> insert position (one per TP leg)
- trade.executed(modify) -> update SL/TP
- trade.executed(close)  -> reduce volume / mark closed, emit trade.closed
- account.snapshot       -> reconcile: positions the broker no longer reports were closed
                            at the broker (SL/TP hit, manual) -> mark closed, emit trade.closed
"""
from __future__ import annotations

from ...common.events import AccountSnapshot, Event, TradeClosed, TradeResult, Topic
from ...common.ids import new_id
from ...common.log import ctx
from ...common.service import Context


class PositionTracker:
    def __init__(self, c: Context):
        self.c = c

    async def handle(self, ev: Event) -> None:
        if ev.topic == Topic.ACCOUNT_SNAPSHOT:
            return await self.reconcile(ev.parse(), ev.trace_id)
        tr: TradeResult = ev.parse()
        if tr.status != "executed" or not tr.ticket:
            return
        db = self.c.db
        if tr.action == "open":
            await db.execute(
                "insert into positions (id, user_id, broker_account_id, channel_id, source_message_id, signal_id, "
                "tp_index, ticket, symbol, side, volume, open_price, sl, tp) "
                "values ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14) on conflict (broker_account_id, ticket) do nothing",
                new_id("pos"), tr.user_id, tr.broker_account_id, tr.channel_id, tr.source_message_id, tr.signal_id,
                tr.tp_index, tr.ticket, tr.symbol, tr.side, tr.volume, tr.price, tr.sl, tr.tp)
        elif tr.action == "modify":
            await db.execute("update positions set sl = coalesce($3, sl), tp = coalesce($4, tp) "
                             "where broker_account_id = $1 and ticket = $2", tr.broker_account_id, tr.ticket, tr.sl, tr.tp)
        elif tr.action == "close":
            row = await db.fetchrow(
                "update positions set volume = greatest(volume - $3, 0), "
                "status = case when volume - $3 <= 0.0000001 then 'closed' else status end, "
                "closed_at = case when volume - $3 <= 0.0000001 then now() else closed_at end, "
                "close_price = $4, profit = coalesce(profit, 0) + coalesce($5, 0) "
                "where broker_account_id = $1 and ticket = $2 and status = 'open' returning status, user_id, symbol",
                tr.broker_account_id, tr.ticket, tr.closed_volume or tr.volume or 0, tr.price, tr.profit)
            if row and row["status"] == "closed":
                await self.c.bus.publish(Topic.TRADE_CLOSED, TradeClosed(
                    user_id=row["user_id"], broker_account_id=tr.broker_account_id, ticket=tr.ticket,
                    symbol=row["symbol"], close_price=tr.price, profit=tr.profit, reason="intent"), trace_id=ev.trace_id)
        self.c.log.info("position updated", extra=ctx(trace_id=ev.trace_id, action=tr.action, ticket=tr.ticket))

    async def reconcile(self, snap: AccountSnapshot, trace_id: str) -> None:
        live = {p.ticket: p for p in snap.positions}
        rows = await self.c.db.fetch("select ticket, symbol, user_id from positions where broker_account_id=$1 "
                                     "and status='open' and opened_at < now() - interval '10 seconds'",
                                     snap.broker_account_id)
        for r in rows:
            if r["ticket"] in live:
                p = live[r["ticket"]]
                await self.c.db.execute("update positions set sl=$3, tp=$4, volume=$5 where broker_account_id=$1 "
                                        "and ticket=$2", snap.broker_account_id, r["ticket"], p.sl, p.tp, p.volume)
                continue
            await self.c.db.execute("update positions set status='closed', closed_at=now() where "
                                    "broker_account_id=$1 and ticket=$2", snap.broker_account_id, r["ticket"])
            await self.c.bus.publish(Topic.TRADE_CLOSED, TradeClosed(
                user_id=r["user_id"], broker_account_id=snap.broker_account_id, ticket=r["ticket"],
                symbol=r["symbol"], reason="broker"), trace_id=trace_id)
            self.c.log.info("position closed at broker", extra=ctx(ticket=r["ticket"], account=snap.broker_account_id))


async def main(c: Context) -> None:
    t = PositionTracker(c)
    await c.bus.subscribe([Topic.TRADE_EXECUTED, Topic.ACCOUNT_SNAPSHOT], t.handle, consumer=c.consumer,
                          concurrency=1)  # ordered per stream: open before modify
