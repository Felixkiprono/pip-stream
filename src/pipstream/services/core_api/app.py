"""Core API: the only public service. Source of truth for config; admin endpoints for the
experiment (bearer admin token); payment webhooks; parse preview (parser studio backend).

Writes publish config.changed so workers reload. Credentials are write-only: they are
encrypted on the way in and never returned.
"""
from __future__ import annotations

import hmac
from contextlib import asynccontextmanager
from typing import Any

from fastapi import Depends, FastAPI, Header, HTTPException, Request
from pydantic import BaseModel
from redis.asyncio import Redis

from ...common.bus import Bus
from ...common.db import create_pool
from ...common.events import BillingWebhook, ConfigChanged, SignalSource, Topic
from ...common.ids import new_id
from ...common.log import setup_logging
from ...common.settings import Settings
from ...common.vault import SecretBox
from ..parser.engine import ParserProfile, SignalParser, SymbolCatalog


class State:
    settings: Settings
    db: Any
    redis: Redis
    bus: Bus
    box: SecretBox | None
    parser: SignalParser


S = State()


@asynccontextmanager
async def lifespan(app: FastAPI):
    S.settings = Settings.from_env(service_name="core_api")
    setup_logging("core_api", S.settings.log_level)
    S.db = await create_pool(S.settings.database_url)
    S.redis = Redis.from_url(S.settings.redis_url, decode_responses=True)
    S.bus = Bus(S.redis, "core_api", prefix=S.settings.stream_prefix)
    S.box = SecretBox(S.settings.secrets_master_keys) if S.settings.secrets_master_keys else None
    S.parser = SignalParser(SymbolCatalog.load(S.settings.symbols_file))
    yield
    await S.db.close()
    await S.redis.aclose()


app = FastAPI(title="PipStream Core API", version="0.1.0", lifespan=lifespan)


def admin(authorization: str = Header(default="")) -> str:
    token = S.settings.admin_token
    if not token or not hmac.compare_digest(authorization.removeprefix("Bearer "), token):
        raise HTTPException(401, "unauthorized")
    return "admin"


async def audit(actor: str, action: str, entity: str, entity_id: str | None, detail: dict | None = None):
    await S.db.execute("insert into audit_log (actor, action, entity, entity_id, detail) values ($1,$2,$3,$4,$5)",
                       actor, action, entity, entity_id, detail or {})


async def changed(entity: str, entity_id: str | None = None):
    await S.bus.publish(Topic.CONFIG_CHANGED, ConfigChanged(entity=entity, entity_id=entity_id))


@app.get("/health")
async def health():
    await S.db.fetchval("select 1")
    return {"ok": True}


@app.get("/v1/admin/status", dependencies=[Depends(admin)])
async def status():
    services = ["ingestor", "parser", "risk_engine", "execution_router", "position_tracker", "notifications", "billing"]
    import time
    out = {}
    for s in services:
        v = await S.redis.get(f"{S.settings.stream_prefix}:health:{s}")
        out[s] = {"alive": bool(v and time.time() - float(v) < 30)}
    return {"services": out}


# ---------------------------------------------------------------- channels & parser studio
class ChannelIn(BaseModel):
    id: str | None = None
    tg_chat_id: int
    title: str | None = None
    parser_profile_id: str | None = None
    active: bool = True


@app.get("/v1/admin/channels", dependencies=[Depends(admin)])
async def list_channels():
    return [dict(r) for r in await S.db.fetch("select * from channels order by created_at")]


@app.post("/v1/admin/channels")
async def upsert_channel(ch: ChannelIn, actor: str = Depends(admin)):
    cid = ch.id or new_id("ch")
    await S.db.execute("insert into channels (id, tg_chat_id, title, parser_profile_id, active) values ($1,$2,$3,$4,$5) "
                       "on conflict (id) do update set tg_chat_id=excluded.tg_chat_id, title=excluded.title, "
                       "parser_profile_id=excluded.parser_profile_id, active=excluded.active",
                       cid, ch.tg_chat_id, ch.title, ch.parser_profile_id, ch.active)
    await audit(actor, "channel_upsert", "channel", cid)
    await changed("channel", cid)
    return {"id": cid}


class ProfileIn(BaseModel):
    id: str
    name: str
    min_confidence: float = 0.8
    config: dict = {}


@app.post("/v1/admin/parser-profiles")
async def upsert_profile(p: ProfileIn, actor: str = Depends(admin)):
    ParserProfile.model_validate({**p.config, "id": p.id})  # validate before saving
    await S.db.execute("insert into parser_profiles (id, name, min_confidence, config) values ($1,$2,$3,$4) "
                       "on conflict (id) do update set name=excluded.name, min_confidence=excluded.min_confidence, "
                       "config=excluded.config, version=parser_profiles.version+1, updated_at=now()",
                       p.id, p.name, p.min_confidence, p.config)
    await audit(actor, "parser_profile_upsert", "parser_profile", p.id)
    await changed("parser_profile", p.id)
    return {"id": p.id}


class PreviewIn(BaseModel):
    text: str
    channel_id: str | None = None
    reply_to_id: int | None = None
    profile: dict | None = None  # try an unsaved profile


@app.post("/v1/admin/parse-preview", dependencies=[Depends(admin)])
async def parse_preview(body: PreviewIn):
    profile = ParserProfile()
    if body.profile is not None:
        profile = ParserProfile.model_validate(body.profile)
    elif body.channel_id:
        r = await S.db.fetchrow("select p.* from channels c join parser_profiles p on p.id=c.parser_profile_id "
                                "where c.id=$1", body.channel_id)
        if r:
            profile = ParserProfile.model_validate({**r["config"], "id": r["id"],
                                                    "min_confidence": float(r["min_confidence"])})
    res = S.parser.parse(body.text, channel_id=body.channel_id or "preview",
                         source=SignalSource(message_id=0, reply_to_id=body.reply_to_id), profile=profile)
    return {"signal": res.signal.model_dump(mode="json", by_alias=True) if res.signal else None,
            "rejected": res.rejected.model_dump(mode="json") if res.rejected else None, "issues": res.issues}


# ---------------------------------------------------------------- brokers & rules
class BrokerAccountIn(BaseModel):
    id: str | None = None
    user_id: str
    connector: str
    label: str | None = None
    credentials: dict | None = None  # write-only
    symbol_map: dict = {}
    enabled: bool = True


@app.get("/v1/admin/broker-accounts", dependencies=[Depends(admin)])
async def list_broker_accounts():
    rows = await S.db.fetch("select id, user_id, connector, label, symbol_map, enabled, "
                            "credentials_ciphertext is not null as has_credentials from broker_accounts")
    return [dict(r) for r in rows]


@app.post("/v1/admin/broker-accounts")
async def upsert_broker_account(a: BrokerAccountIn, actor: str = Depends(admin)):
    if a.credentials and S.box is None:
        raise HTTPException(500, "secrets key not configured")
    aid = a.id or new_id("ba")
    cipher = S.box.encrypt(a.credentials) if a.credentials else None
    await S.db.execute("insert into broker_accounts (id, user_id, connector, label, credentials_ciphertext, symbol_map, "
                       "enabled) values ($1,$2,$3,$4,$5,$6,$7) on conflict (id) do update set connector=excluded.connector, "
                       "label=excluded.label, credentials_ciphertext=coalesce(excluded.credentials_ciphertext, "
                       "broker_accounts.credentials_ciphertext), symbol_map=excluded.symbol_map, enabled=excluded.enabled",
                       aid, a.user_id, a.connector, a.label, cipher, a.symbol_map, a.enabled)
    await audit(actor, "broker_account_upsert", "broker_account", aid, {"credentials_changed": bool(cipher)})
    await changed("broker_account", aid)
    return {"id": aid}


class CopyRuleIn(BaseModel):
    id: str | None = None
    user_id: str
    channel_id: str
    broker_account_id: str
    enabled: bool = True
    mode: str = "shadow"
    sizing_mode: str = "fixed"
    fixed_lots: float | None = 0.01
    risk_pct: float | None = None
    multiplier: float | None = None
    stake: float | None = None
    max_lot: float = 1.0
    tp_split: str = "equal"
    filters: dict = {}
    limits: dict = {}


@app.get("/v1/admin/copy-rules", dependencies=[Depends(admin)])
async def list_rules():
    return [dict(r) for r in await S.db.fetch("select * from copy_rules order by created_at")]


@app.post("/v1/admin/copy-rules")
async def upsert_rule(r: CopyRuleIn, actor: str = Depends(admin)):
    rid = r.id or new_id("rule")
    await S.db.execute(
        "insert into copy_rules (id, user_id, channel_id, broker_account_id, enabled, mode, sizing_mode, fixed_lots, "
        "risk_pct, multiplier, stake, max_lot, tp_split, filters, limits) values ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,"
        "$12,$13,$14,$15) on conflict (id) do update set enabled=excluded.enabled, mode=excluded.mode, "
        "sizing_mode=excluded.sizing_mode, fixed_lots=excluded.fixed_lots, risk_pct=excluded.risk_pct, "
        "multiplier=excluded.multiplier, stake=excluded.stake, max_lot=excluded.max_lot, tp_split=excluded.tp_split, "
        "filters=excluded.filters, limits=excluded.limits",
        rid, r.user_id, r.channel_id, r.broker_account_id, r.enabled, r.mode, r.sizing_mode, r.fixed_lots, r.risk_pct,
        r.multiplier, r.stake, r.max_lot, r.tp_split, r.filters, r.limits)
    await audit(actor, "copy_rule_upsert", "copy_rule", rid, {"mode": r.mode})
    await changed("copy_rule", rid)
    return {"id": rid}


# ---------------------------------------------------------------- kill switch
class KillSwitchIn(BaseModel):
    on: bool
    user_id: str | None = None  # None = global


@app.post("/v1/admin/kill-switch")
async def kill_switch(k: KillSwitchIn, actor: str = Depends(admin)):
    p = S.settings.stream_prefix
    key = f"{p}:killswitch:user:{k.user_id}" if k.user_id else f"{p}:killswitch:global"
    await S.redis.set(key, "1" if k.on else "0")
    if k.user_id:
        await S.db.execute("update users set kill_switch=$2 where id=$1", k.user_id, k.on)
    await audit(actor, "kill_switch", "user" if k.user_id else "global", k.user_id, {"on": k.on})
    await changed("kill_switch", k.user_id)
    return {"key": key, "on": k.on}


# ---------------------------------------------------------------- trace / history
@app.get("/v1/admin/signals", dependencies=[Depends(admin)])
async def signals(channel_id: str | None = None, status: str | None = None, limit: int = 50):
    rows = await S.db.fetch("select id, channel_id, message_id, status, kind, symbol, side, confidence, reason, "
                            "trace_id, created_at from signals where ($1::text is null or channel_id=$1) and "
                            "($2::text is null or status=$2) order by created_at desc limit $3",
                            channel_id, status, min(limit, 500))
    return [dict(r) for r in rows]


@app.get("/v1/admin/trace/{trace_id}", dependencies=[Depends(admin)])
async def trace(trace_id: str):
    """Everything that happened to one Telegram post: signal -> intents -> orders -> positions."""
    sig = await S.db.fetch("select * from signals where trace_id=$1", trace_id)
    intents = await S.db.fetch("select id, copy_rule_id, broker_account_id, action, payload->>'volume' as volume, "
                               "payload->>'tp' as tp, expires_at from trade_intents where trace_id=$1", trace_id)
    orders = await S.db.fetch("select * from orders where trace_id=$1", trace_id)
    sig_ids = [s["id"] for s in sig]
    positions = await S.db.fetch("select * from positions where signal_id = any($1::text[])", sig_ids)
    return {"signals": [dict(r) for r in sig], "intents": [dict(r) for r in intents],
            "orders": [dict(r) for r in orders], "positions": [dict(r) for r in positions]}


# ---------------------------------------------------------------- payments
@app.post("/v1/billing/webhooks/{provider}")
async def billing_webhook(provider: str, request: Request):
    body = (await request.body()).decode()
    headers = {k: v for k, v in request.headers.items() if k.lower().startswith(("x-", "content-type"))}
    await S.bus.publish(Topic.BILLING_WEBHOOK, BillingWebhook(provider=provider, headers=headers, body=body))
    return {"received": True}  # verification happens in the billing service
