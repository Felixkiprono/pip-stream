"""Rendezvous (highest-random-weight) hashing of Telegram accounts onto ingestor shards.
Adding a shard moves only ~1/N of the sessions."""
from __future__ import annotations

import hashlib


def owner_shard(account_id: str, shards: int) -> int:
    best, best_w = 0, -1
    for s in range(shards):
        w = int.from_bytes(hashlib.blake2b(f"{account_id}|{s}".encode(), digest_size=8).digest(), "big")
        if w > best_w:
            best, best_w = s, w
    return best


def assign_readers(readers: list[dict], live_accounts: set[str]) -> dict[str, str]:
    """channel_id -> telegram_account_id that should emit its posts.
    Read each channel ONCE: the active reader if its session is live, else a live standby."""
    by_channel: dict[str, list[dict]] = {}
    for r in readers:
        by_channel.setdefault(r["channel_id"], []).append(r)
    out: dict[str, str] = {}
    for ch, rs in by_channel.items():
        rs.sort(key=lambda r: (r["role"] != "active", r["telegram_account_id"]))
        for r in rs:
            if r["telegram_account_id"] in live_accounts:
                out[ch] = r["telegram_account_id"]
                break
    return out
