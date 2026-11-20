"""DEV/TEST ONLY: load config/pipstream.yaml into Postgres (idempotent upserts).

In production everything here is managed in the web app (Laravel); Laravel also has
`php artisan pipstream:import-config` for the same YAML. This copy lets the Python tests and
local replay demos run without PHP. Secrets are never written in the YAML; broker accounts
reference env vars via `credentials_env` and are seeded DISABLED if any is missing.
"""
from __future__ import annotations

import os
from pathlib import Path

import asyncpg
import yaml

from ..common.vault import seal


def _creds(acc: dict) -> tuple[dict, bool]:
    creds = dict(acc.get("credentials") or {})
    ok = True
    for field, env in (acc.get("credentials_env") or {}).items():
        val = os.environ.get(env)
        if val in (None, ""):
            ok = False
        else:
            creds[field] = val
    return creds, ok


async def seed(pool: asyncpg.Pool, path: str, executor_public_key: str | None) -> dict[str, int]:
    cfg = yaml.safe_load(Path(path).read_text())
    counts: dict[str, int] = {}

    def n(k):
        counts[k] = counts.get(k, 0) + 1

    async with pool.acquire() as db, db.transaction():
        for p in cfg.get("plans", []):
            await db.execute("insert into plans (id, name, max_accounts, allow_live, price_cents, currency) "
                             "values ($1,$2,$3,$4,$5,$6) on conflict (id) do update set name=excluded.name, "
                             "max_accounts=excluded.max_accounts, allow_live=excluded.allow_live, "
                             "price_cents=excluded.price_cents, currency=excluded.currency",
                             p["id"], p["name"], p.get("max_accounts", 1), p.get("allow_live", False),
                             p.get("price_cents", 0), p.get("currency", "KES"))
            n("plans")
        for c in cfg.get("connectors", []):
            await db.execute("insert into connectors (name, kind, enabled, config) values ($1,$2,$3,$4) "
                             "on conflict (name) do update set kind=excluded.kind, enabled=excluded.enabled, "
                             "config=excluded.config", c["name"], c["kind"], c.get("enabled", True), c.get("config", {}))
            n("connectors")
        for u in cfg.get("users", []):
            await db.execute("insert into users (id, email, name, role) values ($1,$2,$3,$4) on conflict (id) do "
                             "update set email=excluded.email, name=excluded.name, role=excluded.role",
                             u["id"], u["email"], u.get("name") or u.get("display_name"), u.get("role", "customer"))
            if plan := u.get("plan"):
                await db.execute("insert into billing_subscriptions (id, user_id, plan_id, status, provider) "
                                 "values ($1,$2,$3,'trialing','seed') on conflict (user_id) do update set "
                                 "plan_id=excluded.plan_id", f"bsub_{u['id']}", u["id"], plan)
            for pref in u.get("notify", []):
                addr = pref.get("address") or os.environ.get(pref.get("address_env", ""), None)
                await db.execute("insert into notification_prefs (user_id, channel, address) values ($1,$2,$3) "
                                 "on conflict (user_id, channel) do update set address=excluded.address",
                                 u["id"], pref["channel"], addr)
            n("users")
        for t in cfg.get("telegram_accounts", []):
            await db.execute("insert into telegram_accounts (id, user_id, status) values ($1,$2,'active') "
                             "on conflict (id) do nothing", t["id"], t["user_id"])
            n("telegram_accounts")
        for p in cfg.get("parser_profiles", []):
            body = {k: v for k, v in p.items() if k not in ("id", "name", "version", "min_confidence")}
            await db.execute("insert into parser_profiles (id, name, version, min_confidence, config) "
                             "values ($1,$2,$3,$4,$5) on conflict (id) do update set name=excluded.name, "
                             "version=excluded.version, min_confidence=excluded.min_confidence, "
                             "config=excluded.config, updated_at=now()",
                             p["id"], p.get("name", p["id"]), p.get("version", 1), p.get("min_confidence", 0.8), body)
            n("parser_profiles")
        for ch in cfg.get("channels", []):
            await db.execute("insert into channels (id, tg_chat_id, title, username, parser_profile_id, active) "
                             "values ($1,$2,$3,$4,$5,$6) on conflict (id) do update set tg_chat_id=excluded.tg_chat_id, "
                             "title=excluded.title, username=excluded.username, "
                             "parser_profile_id=excluded.parser_profile_id, active=excluded.active",
                             ch["id"], int(ch["tg_chat_id"]), ch.get("title"), ch.get("username"),
                             ch.get("parser_profile"), ch.get("active", True))
            for r in ch.get("readers", []):
                await db.execute("insert into channel_readers (channel_id, telegram_account_id, role) values ($1,$2,$3) "
                                 "on conflict (channel_id, telegram_account_id) do update set role=excluded.role",
                                 ch["id"], r["account"], r.get("role", "active"))
            for uid in ch.get("subscribers", []):
                await db.execute("insert into subscriptions (user_id, channel_id) values ($1,$2) on conflict do nothing",
                                 uid, ch["id"])
            n("channels")
        for a in cfg.get("broker_accounts", []):
            creds, ok = _creds(a)
            cipher = seal(creds, executor_public_key) if (executor_public_key and creds) else None
            enabled = a.get("enabled", True) and ok
            await db.execute("insert into broker_accounts (id, user_id, connector, label, credentials_ciphertext, "
                             "symbol_map, enabled) values ($1,$2,$3,$4,$5,$6,$7) on conflict (id) do update set "
                             "connector=excluded.connector, label=excluded.label, "
                             "credentials_ciphertext=coalesce(excluded.credentials_ciphertext, broker_accounts.credentials_ciphertext), "
                             "symbol_map=excluded.symbol_map, enabled=excluded.enabled",
                             a["id"], a["user_id"], a["connector"], a.get("label"), cipher, a.get("symbol_map", {}), enabled)
            n("broker_accounts" if enabled else "broker_accounts_disabled")
        for r in cfg.get("copy_rules", []):
            await db.execute(
                "insert into copy_rules (id, user_id, channel_id, broker_account_id, enabled, mode, sizing_mode, "
                "fixed_lots, risk_pct, multiplier, stake, max_lot, tp_split, filters, limits) values "
                "($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15) on conflict (id) do update set "
                "enabled=excluded.enabled, mode=excluded.mode, sizing_mode=excluded.sizing_mode, "
                "fixed_lots=excluded.fixed_lots, risk_pct=excluded.risk_pct, multiplier=excluded.multiplier, "
                "stake=excluded.stake, max_lot=excluded.max_lot, tp_split=excluded.tp_split, "
                "filters=excluded.filters, limits=excluded.limits",
                r["id"], r["user_id"], r["channel_id"], r["broker_account_id"], r.get("enabled", True),
                r.get("mode", "shadow"), r.get("sizing_mode", "fixed"), r.get("fixed_lots"), r.get("risk_pct"),
                r.get("multiplier"), r.get("stake"), r.get("max_lot", 1.0), r.get("tp_split", "equal"),
                r.get("filters", {}), r.get("limits", {}))
            n("copy_rules")
        await db.execute("insert into audit_log (actor, action, detail) values ('seed', 'config_seeded', $1)", counts)
    return counts
