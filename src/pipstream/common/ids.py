"""Sortable, prefixed identifiers (ULID-style: 48-bit ms time + 80 random bits)."""
from __future__ import annotations

import os
import time

_B32 = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"


def ulid() -> str:
    n = (int(time.time() * 1000) << 80) | int.from_bytes(os.urandom(10), "big")
    out = []
    for _ in range(26):
        out.append(_B32[n & 31])
        n >>= 5
    return "".join(reversed(out))


def new_id(prefix: str) -> str:
    return f"{prefix}_{ulid()}"
