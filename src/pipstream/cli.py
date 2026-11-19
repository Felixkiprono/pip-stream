"""pipstream CLI.

  pipstream migrate            apply DB migrations
  pipstream seed [FILE]        load config/pipstream.yaml into Postgres
  pipstream keys               generate fresh secrets for .env (signing keys, master key, passwords)
  pipstream acl                render the Redis ACL file from env passwords
  pipstream run SERVICE        run a worker (ingestor|parser|risk_engine|execution_router|...)
"""
from __future__ import annotations

import argparse
import asyncio
import runpy
import secrets
import sys

from .common.permissions import SERVICES, render_acl
from .common.settings import Settings
from .common.signing import generate_keypair
from .common.vault import SecretBox


async def _migrate(s: Settings) -> None:
    from .common.db import create_pool, migrate
    pool = await create_pool(s.database_url)
    applied = await migrate(pool)
    await pool.close()
    print("applied:", applied or "nothing new")


async def _seed(s: Settings, path: str) -> None:
    from .common.db import create_pool
    from .services.core_api.seed import seed
    pool = await create_pool(s.database_url)
    box = SecretBox(s.secrets_master_keys) if s.secrets_master_keys else None
    counts = await seed(pool, path, box)
    await pool.close()
    print("seeded:", counts)


def main(argv: list[str] | None = None) -> None:
    ap = argparse.ArgumentParser(prog="pipstream")
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("migrate")
    sp = sub.add_parser("seed")
    sp.add_argument("file", nargs="?")
    sub.add_parser("keys")
    sub.add_parser("acl")
    rp = sub.add_parser("run")
    rp.add_argument("service")
    a = ap.parse_args(argv)
    s = Settings.from_env()
    if a.cmd == "migrate":
        asyncio.run(_migrate(s))
    elif a.cmd == "seed":
        asyncio.run(_seed(s, a.file or s.config_file))
    elif a.cmd == "keys":
        priv, pub = generate_keypair()
        print(f"PS_INTENT_SIGNING_KEY={priv}\nPS_INTENT_VERIFY_KEY={pub}")
        print(f"PS_SECRETS_MASTER_KEYS={SecretBox.generate_key()}")
        print(f"PS_ADMIN_TOKEN={secrets.token_urlsafe(32)}")
        print(f"POSTGRES_PASSWORD={secrets.token_urlsafe(24)}")
        for svc in ["admin", *SERVICES]:
            print(f"PS_REDIS_PASSWORD_{svc.upper()}={secrets.token_urlsafe(24)}")
    elif a.cmd == "acl":
        sys.stdout.write(render_acl())
    elif a.cmd == "run":
        sys.argv = [a.service]
        runpy.run_module(f"pipstream.services.{a.service}", run_name="__main__")


if __name__ == "__main__":
    main()
