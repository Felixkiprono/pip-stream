"""Message templates (English now; Swahili later - move to DB, versioned, per handoff §4)."""
from __future__ import annotations

TEMPLATES = {
    "en": {
        "trade.executed": ("PipStream: {action} {side} {symbol}",
                           "{mode} {action} {side} {volume} {symbol} @ {price} (ticket {ticket}, {latency_ms} ms)"),
        "trade.failed": ("PipStream: trade failed", "{action} {symbol} on {broker_account_id} failed: {error}"),
        "trade.closed": ("PipStream: position closed", "{symbol} ticket {ticket} closed ({reason}) profit {profit}"),
        "risk.limit_hit": ("PipStream: signal skipped", "Rule {copy_rule_id}: {limit} {detail}"),
    },
}


def render(event: str, data: dict, lang: str = "en") -> tuple[str, str]:
    subj, body = TEMPLATES.get(lang, TEMPLATES["en"])[event]
    safe = {k: ("" if v is None else v) for k, v in data.items()}
    return subj.format_map(_Default(safe)), body.format_map(_Default(safe))


class _Default(dict):
    def __missing__(self, key):
        return ""
