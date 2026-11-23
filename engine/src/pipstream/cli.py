"""pipstream CLI (Python engine).

  pipstream run engine                 run the engine: intake, risk and executor as child processes
  pipstream run intake|risk|executor   run one worker on its own (dev, or to scale one part)
  pipstream keys                       print fresh secrets for the site-root .env
  pipstream acl                        render the Redis ACL file from PS_REDIS_PASSWORD_* env vars
  pipstream acl --apply                create those users on a running Redis (no ACL file needed)
  pipstream health SERVICE             container healthcheck (heartbeat under 30 s old)
  pipstream dev-schema                 apply database/schema/pipstream.sql to an EMPTY dev database
  pipstream dev-seed [FILE]            load config/pipstream.yaml (dev / replay demos only)
  pipstream fake-post CHAT_ID TEXT     stand-in Telegram (PS_INGESTOR_FAKE_TELEGRAM): post TEXT in a channel
  pipstream fake-saved                 stand-in Telegram: print the Saved Messages notes intake wrote

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


async def _fake_post(s: Settings, chat_id: int, text: str, message_id: int | None, edit: bool,
                     reply_to: int | None) -> None:
    import json
    import time

    from redis.asyncio import Redis

    from .common.db import create_pool
    from .devtools.fake_telegram import inbox_key
    pool = await create_pool(s.database_url)
    accounts = [r["id"] for r in await pool.fetch(
        "select id from telegram_accounts where status = 'active' and session_ciphertext is not null")]
    await pool.close()
    if not accounts:
        raise SystemExit("no linked Telegram account: connect one in the web app first (code 12345)")
    mid = message_id or int(time.time() * 1000) % 2_000_000_000
    r = Redis.from_url(s.redis_url, decode_responses=True)
    body = json.dumps({"tg_chat_id": chat_id, "message_id": mid, "text": text, "edit": edit, "reply_to_id": reply_to})
    for acc in accounts:
        await r.rpush(f"{inbox_key(s.stream_prefix)}:{acc}", body)
    await r.aclose()
    print(f"posted message {mid} in chat {chat_id} to {len(accounts)} linked account(s)")


async def _fake_saved(s: Settings) -> None:
    import json
    import time

    from redis.asyncio import Redis

    from .devtools.fake_telegram import saved_key
    r = Redis.from_url(s.redis_url, decode_responses=True)
    for raw in await r.lrange(saved_key(s.stream_prefix), 0, -1):
        d = json.loads(raw)
        print(f"--- {time.strftime('%H:%M:%S', time.localtime(d['ts']))} ({d['telegram_account_id']})\n{d['text']}")
    await r.aclose()


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
    ap_acl = sub.add_parser("acl")
    ap_acl.add_argument("--apply", action="store_true",
                        help="create the users on the running Redis (PS_REDIS_ADMIN_URL, or REDIS_URL with PS_REDIS_APPLY_ACL=1)")
    hp = sub.add_parser("health")
    hp.add_argument("app", choices=APPS)
    sub.add_parser("dev-schema")
    sp = sub.add_parser("dev-seed")
    sp.add_argument("file", nargs="?")
    fp = sub.add_parser("fake-post")
    fp.add_argument("chat_id", type=int)
    fp.add_argument("text")
    fp.add_argument("--message-id", type=int)
    fp.add_argument("--edit", action="store_true", help="edit of --message-id")
    fp.add_argument("--reply-to", type=int)
    sub.add_parser("fake-saved")
    a = ap.parse_args(argv)
    s = Settings.from_env()
    if a.cmd == "run":
        sys.argv = [a.app]
        runpy.run_module(f"pipstream.apps.{a.app}", run_name="__main__")
    elif a.cmd == "keys":
        sys.stdout.write(keys())
    elif a.cmd == "acl":
        if a.apply:
            import os
            from .common.permissions import admin_url_from_env, apply_acl
            env = dict(os.environ)
            url = admin_url_from_env(env)
            if not url:
                raise SystemExit("set PS_REDIS_ADMIN_URL, or PS_REDIS_APPLY_ACL=1 with REDIS_URL")
            print("applied:", ", ".join(apply_acl(url, env, s.stream_prefix)))
        else:
            sys.stdout.write(render_acl())
    elif a.cmd == "health":
        from .common.healthcheck import main as health
        workers = ("intake", "risk", "executor") if a.app == "engine" else (a.app,)
        sys.exit(max(health(w) for w in workers))
    elif a.cmd == "dev-schema":
        asyncio.run(_schema(s))
    elif a.cmd == "fake-post":
        asyncio.run(_fake_post(s, a.chat_id, a.text, a.message_id, a.edit, a.reply_to))
    elif a.cmd == "fake-saved":
        asyncio.run(_fake_saved(s))
    elif a.cmd == "dev-seed":
        asyncio.run(_seed(s, a.file or s.config_file))


if __name__ == "__main__":
    main()
