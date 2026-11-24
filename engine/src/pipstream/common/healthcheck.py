"""Container healthcheck: exit 0 if this service wrote a heartbeat in the last 30 s."""
from __future__ import annotations

import sys
import time

import redis

from .settings import Settings


def main(service: str) -> int:
    import os
    from ..apps.engine import child_env
    s = Settings.from_env()
    url = child_env(service, dict(os.environ))["PS_REDIS_URL"]
    r = redis.Redis.from_url(url, decode_responses=True, socket_timeout=3)
    v = r.get(f"{s.stream_prefix}:health:{service}")
    return 0 if v and time.time() - float(v) < 30 else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1]))
