"""Postgres access (asyncpg) and a tiny forward-only migration runner."""
from __future__ import annotations

import json
import logging
from importlib import resources

import asyncpg

log = logging.getLogger("pipstream.db")


async def _init_conn(conn: asyncpg.Connection) -> None:
    await conn.set_type_codec("jsonb", encoder=json.dumps, decoder=json.loads, schema="pg_catalog")
    await conn.set_type_codec("json", encoder=json.dumps, decoder=json.loads, schema="pg_catalog")


async def create_pool(dsn: str, min_size: int = 1, max_size: int = 10) -> asyncpg.Pool:
    return await asyncpg.create_pool(dsn, min_size=min_size, max_size=max_size, init=_init_conn)


async def migrate(pool: asyncpg.Pool) -> list[str]:
    applied: list[str] = []
    async with pool.acquire() as conn:
        await conn.execute("create table if not exists schema_migrations "
                           "(name text primary key, applied_at timestamptz not null default now())")
        done = {r["name"] for r in await conn.fetch("select name from schema_migrations")}
        files = sorted(p for p in resources.files("pipstream.db").joinpath("migrations").iterdir()
                       if p.name.endswith(".sql"))
        for f in files:
            if f.name in done:
                continue
            async with conn.transaction():
                await conn.execute(f.read_text())
                await conn.execute("insert into schema_migrations(name) values($1)", f.name)
            applied.append(f.name)
            log.info("applied migration %s", f.name)
    return applied
