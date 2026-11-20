"""Lot sizing and symbol resolution (pure functions, unit-tested)."""
from __future__ import annotations

import math

from ...common.events import SymbolSpec


class SizingError(ValueError):
    pass


def round_down(volume: float, step: float) -> float:
    if step <= 0:
        return volume
    return round(math.floor(volume / step + 1e-9) * step, 8)


def risk_lots(balance: float, risk_pct: float, entry: float, sl: float, spec: SymbolSpec) -> float:
    """lots = (balance x risk%) / (SL distance in ticks x tick value per lot)."""
    dist = abs(entry - sl)
    if dist <= 0:
        raise SizingError("zero SL distance")
    loss_per_lot = dist / spec.tick_size * spec.tick_value
    if loss_per_lot <= 0:
        raise SizingError("invalid tick value")
    return balance * (risk_pct / 100.0) / loss_per_lot


def finalize(volume: float, spec: SymbolSpec, max_lot: float) -> float:
    v = round_down(min(volume, max_lot, spec.volume_max), spec.volume_step)
    if v < spec.volume_min:
        raise SizingError(f"volume {volume:.4f} below broker minimum {spec.volume_min}")
    return v


def split_tps(total: float, n_tps: int, spec: SymbolSpec, mode: str = "equal") -> list[float]:
    """Split `total` lots across TP legs. Legs that would fall below the minimum lot are
    merged into earlier legs (fewer TPs rather than an invalid order)."""
    if n_tps <= 1 or mode == "first_only":
        return [total]
    for n in range(n_tps, 0, -1):
        per = round_down(total / n, spec.volume_step)
        if per >= spec.volume_min:
            legs = [per] * n
            legs[0] = round(legs[0] + round_down(total - per * n, spec.volume_step), 8)
            return legs
    return [total]


def resolve_symbol(canonical: str, symbol_map: dict, available: set[str] | None) -> str | None:
    """Canonical -> broker symbol. Explicit map wins; else exact; else a broker suffix
    variant (XAUUSD.m, XAUUSDm, XAUUSD-ECN)."""
    if canonical in (symbol_map or {}):
        return symbol_map[canonical]
    if not available:
        return canonical
    if canonical in available:
        return canonical
    cands = sorted(s for s in available if s.upper().startswith(canonical.upper()) and len(s) <= len(canonical) + 4)
    return cands[0] if cands else None
