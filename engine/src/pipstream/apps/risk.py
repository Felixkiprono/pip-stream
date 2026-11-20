"""risk = copy rules, entitlement, kill switch, filters, limits, sizing; signs trade intents.
The only process with PS_INTENT_SIGNING_KEY and the only Redis user allowed to write
trade.intent."""
from __future__ import annotations

from ..common.service import Context, run
from ..services.risk_engine.service import main as risk_main


async def main(c: Context) -> None:
    c.status = lambda: {"signing_key": bool(c.settings.intent_signing_key)}
    await risk_main(c)


if __name__ == "__main__":
    run("risk", main)
