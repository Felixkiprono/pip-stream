"""executor = execution router + position tracker. Verifies signed intents, opens broker
credentials (PS_EXECUTOR_PRIVATE_KEY), places orders and keeps positions in sync."""
from __future__ import annotations

import asyncio

from ..common.service import Context, run
from ..services.execution_router.service import main as router_main
from ..services.position_tracker.service import main as tracker_main


async def main(c: Context) -> None:
    import os
    s = c.settings
    c.status = lambda: {
        "verify_key": bool(s.intent_verify_key),
        "executor_key": bool(s.executor_private_key),
        "mt5_configured": any(k.startswith("PS_MT5_") and v for k, v in os.environ.items()),
        "deriv_configured": any(k.startswith("PS_DERIV_") and v for k, v in os.environ.items()),
    }
    await asyncio.gather(router_main(c), tracker_main(c))


if __name__ == "__main__":
    run("executor", main)
