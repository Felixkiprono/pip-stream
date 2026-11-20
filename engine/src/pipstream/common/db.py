"""Postgres access (asyncpg). The schema itself is owned by Laravel."""
from __future__ import annotations

import json
import logging
import os
from pathlib import Path

import asyncpg

log = logging.getLogger("pipstream.db")


async def _init_conn(conn: asyncpg.Connection) -> None:
    await conn.set_type_codec("jsonb", encoder=json.dumps, decoder=json.loads, schema="pg_catalog")
    await conn.set_type_codec("json", encoder=json.dumps, decoder=json.loads, schema="pg_catalog")


async def create_pool(dsn: str, min_size: int = 1, max_size: int = 10) -> asyncpg.Pool:
    return await asyncpg.create_pool(dsn, min_size=min_size, max_size=max_size, init=_init_conn)


def find_schema_file(explicit: str | None = None) -> Path:
    """The schema is owned by Laravel (database/schema/pipstream.sql at the repo root, run by a
    Laravel migration). Python only applies it for tests and local dev."""
    cands = [explicit, os.environ.get("PS_SCHEMA_FILE")]
    here = Path(__file__).resolve()
    cands += [str(p / "database/schema/pipstream.sql") for p in here.parents]
    for c in cands:
        if c and Path(c).is_file():
            return Path(c)
    raise FileNotFoundError("database/schema/pipstream.sql not found; set PS_SCHEMA_FILE")


async def migrate(pool: asyncpg.Pool, schema_file: str | None = None) -> bool:
    """Apply the shared schema to an empty database (tests/dev). Returns False if present."""
    async with pool.acquire() as conn:
        if await conn.fetchval("select to_regclass('public.channels') is not null"):
            return False
        async with conn.transaction():
            await conn.execute(find_schema_file(schema_file).read_text())
    log.info("applied shared schema")
    return True
