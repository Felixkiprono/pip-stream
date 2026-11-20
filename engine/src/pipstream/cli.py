"""pipstream CLI (Python engine).

  pipstream run engine                 run the engine: intake, risk and executor as child processes
  pipstream run intake|risk|executor   run one worker on its own (dev, or to scale one part)
  pipstream keys                       print fresh secrets for the site-root .env
  pipstream acl                        render the Redis ACL file from PS_REDIS_PASSWORD_* env vars
  pipstream health SERVICE             container healthcheck (heartbeat under 30 s old)
  pipstream dev-schema                 apply database/schema/pipstream.sql to an EMPTY dev database
  pipstream dev-seed [FILE]            load config/pipstream.yaml (dev / replay demos only)

In production the schema is applied by Laravel (`php artisan migrate`) and configuration is
managed in the web app; the dev-* commands exist for tests and local replay runs.
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
from .common.vault import generate_keypair as generate_box_keypair

APPS = ("engine", "intake", "risk", "executor")


async def _schema(s: Settings) -> None:
    from .common.db import create_pool, migrate
    pool = await create_pool(s.database_url)
    applied = await migrate(pool, s.schema_file)
    await pool.close()
    print("schema applied" if applied else "schema already present")


async def _seed(s: Settings, path: str) -> None:
    from .common.db import create_pool
    from .devtools.seed import seed
    pool = await create_pool(s.database_url)
    counts = await seed(pool, path, s.executor_public_key)
    await pool.close()
    print("seeded:", counts)


def keys() -> str:
    """Fresh secrets for the site-root .env. Each private key goes ONLY to the container named
    in docker-compose.yml; Laravel gets the two PUBLIC keys."""
    sign, verify = generate_keypair()
    intake_sk, intake_pk = generate_box_keypair()
    exec_sk, exec_pk = generate_box_keypair()
    lines = [
        f"PS_INTENT_SIGNING_KEY={sign}",
        f"PS_INTENT_VERIFY_KEY={verify}",
        f"PS_INTAKE_PRIVATE_KEY={intake_sk}",
        f"PS_INTAKE_PUBLIC_KEY={intake_pk}",
        f"PS_EXECUTOR_PRIVATE_KEY={exec_sk}",
        f"PS_EXECUTOR_PUBLIC_KEY={exec_pk}",
        f"DB_PASSWORD={secrets.token_urlsafe(24)}",
        f"PS_REDIS_PASSWORD_ADMIN={secrets.token_urlsafe(24)}",
    ]
    lines += [f"PS_REDIS_PASSWORD_{svc.upper()}={secrets.token_urlsafe(24)}" for svc in SERVICES]
    return "\n".join(lines) + "\n"


def main(argv: list[str] | None = None) -> None:
    ap = argparse.ArgumentParser(prog="pipstream")
    sub = ap.add_subparsers(dest="cmd", required=True)
    rp = sub.add_parser("run")
    rp.add_argument("app", choices=APPS)
    sub.add_parser("keys")
    sub.add_parser("acl")
    hp = sub.add_parser("health")
    hp.add_argument("app", choices=APPS)
    sub.add_parser("dev-schema")
    sp = sub.add_parser("dev-seed")
    sp.add_argument("file", nargs="?")
    a = ap.parse_args(argv)
    s = Settings.from_env()
    if a.cmd == "run":
        sys.argv = [a.app]
        runpy.run_module(f"pipstream.apps.{a.app}", run_name="__main__")
    elif a.cmd == "keys":
        sys.stdout.write(keys())
    elif a.cmd == "acl":
        sys.stdout.write(render_acl())
    elif a.cmd == "health":
        from .common.healthcheck import main as health
        workers = ("intake", "risk", "executor") if a.app == "engine" else (a.app,)
        sys.exit(max(health(w) for w in workers))
    elif a.cmd == "dev-schema":
        asyncio.run(_schema(s))
    elif a.cmd == "dev-seed":
        asyncio.run(_seed(s, a.file or s.config_file))


if __name__ == "__main__":
    main()
