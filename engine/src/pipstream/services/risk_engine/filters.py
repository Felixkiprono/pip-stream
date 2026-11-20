"""Per-rule filters (cheap, no I/O). Returns a reason string when the signal must be skipped."""
from __future__ import annotations

from datetime import datetime, timezone

from ...common.events import ParsedSignal


def check_filters(sig: ParsedSignal, filters: dict, now: datetime | None = None) -> str | None:
    now = now or datetime.now(timezone.utc)
    allow = [s.upper() for s in filters.get("symbols_allow") or []]
    deny = [s.upper() for s in filters.get("symbols_deny") or []]
    if sig.symbol and allow and sig.symbol.upper() not in allow:
        return "symbol_not_allowed"
    if sig.symbol and sig.symbol.upper() in deny:
        return "symbol_denied"
    if sides := filters.get("sides"):
        if sig.side and sig.side not in sides:
            return "side_not_allowed"
    if days := filters.get("days_utc"):  # e.g. [0,1,2,3,4] = Mon..Fri
        if now.weekday() not in days:
            return "outside_trading_days"
    if hours := filters.get("hours_utc"):  # e.g. [[7, 20]]
        h = now.hour + now.minute / 60
        if not any(a <= h < b for a, b in hours):
            return "outside_trading_hours"
    if (mc := filters.get("min_confidence")) and sig.confidence < float(mc):
        return "below_rule_confidence"
    return None


def entry_passed(sig: ParsedSignal, quote: float | None, max_slippage_points: float | None,
                 point: float) -> str | None:
    """Skip if price already ran past TP1, or is too far from the posted entry."""
    if quote is None or sig.side is None:
        return None
    if sig.tps:
        tp1 = sig.tps[0]
        if (sig.side == "buy" and quote >= tp1) or (sig.side == "sell" and quote <= tp1):
            return "price_beyond_tp1"
    ref = sig.entry.reference_price() if sig.entry else None
    if ref is not None and max_slippage_points:
        if sig.entry.type == "range":
            lo, hi = sorted([sig.entry.from_, sig.entry.to])
            dist = 0 if lo <= quote <= hi else min(abs(quote - lo), abs(quote - hi))
        else:
            dist = abs(quote - ref)
        if dist / point > max_slippage_points:
            return "entry_slippage_exceeded"
    return None
