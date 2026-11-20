"""Execution Router: the only service that holds broker credentials and talks to venues.

For every `trade.intent`:
  1. verify Ed25519 signature (forged intents are dropped and audited, never executed)
  2. reject if expired (5 s TTL) or a kill switch is on, re-check hard lot caps
  3. claim the idempotency key (SET NX) so re-deliveries never double-trade
  4. decrypt credentials in memory, call the connector, retry only RetryableError while valid
  5. publish trade.executed | trade.failed and persist `orders`

A snapshot loop publishes account.snapshot (balance, symbol specs, positions) which the
risk engine uses for sizing and the position tracker uses to reconcile.
"""
from __future__ import annotations

import asyncio
import contextlib
import time
from datetime import datetime, timezone

from ...common.events import Event, TradeIntent, TradeResult, Topic, AccountSnapshot
from ...common.ids import new_id
from ...common.log import ctx
from ...common.service import Context
from ...common.signing import IntentVerifier
from ...common.vault import Opener
from ..parser.engine import SymbolCatalog
from .connectors import registry
from .connectors.base import AuthError, Connector, ConnectorError, RetryableError


class ConnectorPool:
    """One connected connector instance per (broker account, mode)."""

    def __init__(self, c: Context, opener: Opener | None, catalog: SymbolCatalog, factories: dict | None = None):
        self.c, self.opener, self.catalog = c, opener, catalog
        self.factories = factories or {}  # test hooks: connector name -> extra deps
        self._pool: dict[tuple[str, str], Connector] = {}
        self._locks: dict[str, asyncio.Lock] = {}
        self._connect_lock = asyncio.Lock()

    def lock(self, account_id: str) -> asyncio.Lock:
        return self._locks.setdefault(account_id, asyncio.Lock())

    async def get(self, account_id: str, mode: str) -> Connector:
        key = (account_id, mode)
        if key in self._pool:
            return self._pool[key]
        async with self._connect_lock:
            if key in self._pool:
                return self._pool[key]
            row = await self.c.db.fetchrow("select connector, credentials_ciphertext, enabled from broker_accounts "
                                           "where id = $1", account_id)
            if row is None or not row["enabled"]:
                raise ConnectorError(f"broker account {account_id} missing or disabled")
            name = "paper" if mode == "paper" else row["connector"]
            creds = {}
            if name != "paper" and row["credentials_ciphertext"]:
                if self.opener is None:
                    raise ConnectorError("PS_EXECUTOR_PRIVATE_KEY is not set; cannot open broker credentials")
                creds = self.opener.open(row["credentials_ciphertext"])
            conn = registry.create(name, redis=self.c.redis, account_id=account_id,
                                   prefix=self.c.settings.stream_prefix, catalog=self.catalog,
                                   **self.factories.get(name, {}))
            await conn.connect(creds)
            del creds
            self._pool[key] = conn
            return conn

    async def evict(self, account_id: str | None = None) -> None:
        for key in [k for k in self._pool if account_id is None or k[0] == account_id]:
            conn = self._pool.pop(key)
            with contextlib.suppress(Exception):
                await conn.disconnect()


class ExecutionRouter:
    def __init__(self, c: Context, *, factories: dict | None = None):
        self.c = c
        s = c.settings
        if not s.intent_verify_key:
            raise SystemExit("PS_INTENT_VERIFY_KEY is required")
        self.verifier = IntentVerifier.from_setting(s.intent_verify_key, s.intent_key_id)
        opener = Opener(s.executor_private_key) if s.executor_private_key else None
        self.catalog = SymbolCatalog.load(s.symbols_file)
        self.pool = ConnectorPool(c, opener, self.catalog, factories)
        self.p = s.stream_prefix

    async def _kill_switch(self, user_id: str) -> bool:
        vals = await self.c.redis.mget(f"{self.p}:killswitch:global", f"{self.p}:killswitch:user:{user_id}")
        return any(v == "1" for v in vals)

    async def _audit(self, action: str, intent: TradeIntent, detail: dict) -> None:
        await self.c.db.execute("insert into audit_log (actor, action, entity, entity_id, detail) "
                                "values ('executor', $1, 'trade_intent', $2, $3)",
                                action, intent.intent_id, detail)

    # ------------------------------------------------------------------ intents
    async def handle(self, ev: Event) -> None:
        intent: TradeIntent = ev.parse()
        log_ctx = dict(trace_id=ev.trace_id, intent_id=intent.intent_id, account=intent.broker_account_id)

        if not self.verifier.verify(intent):
            self.c.log.error("REJECTED forged/invalid intent signature", extra=ctx(**log_ctx, producer=ev.producer))
            await self._audit("intent_bad_signature", intent, {"producer": ev.producer, "event_id": ev.event_id})
            return
        if self.verifier.expired(intent):
            return await self._finish(ev, intent, error="expired")
        if await self._kill_switch(intent.user_id):
            return await self._finish(ev, intent, error="kill_switch")
        if intent.action == "open" and intent.options is None and intent.volume is not None:
            cap = min(self.c.settings.router_global_max_lot, intent.max_lot or float("inf"))
            if intent.volume > cap:
                return await self._finish(ev, intent, error=f"volume {intent.volume} > cap {cap}")

        claimed = await self.c.redis.set(f"{self.p}:idem:{intent.idempotency_key}", intent.intent_id,
                                         nx=True, ex=86400)
        if not claimed:
            self.c.log.info("duplicate intent ignored", extra=ctx(**log_ctx, key=intent.idempotency_key))
            return

        async with self.pool.lock(intent.broker_account_id):
            delay = 0.05
            while True:
                try:
                    conn = await self.pool.get(intent.broker_account_id, intent.mode)
                    if intent.action == "open":
                        res = await conn.open(intent)
                    elif intent.action == "modify":
                        res = await conn.modify(intent.ticket, intent.sl, intent.tp)
                    else:
                        res = await conn.close(intent.ticket, intent.volume)
                    return await self._finish(ev, intent, result=res)
                except RetryableError as e:
                    await self.pool.evict(intent.broker_account_id)
                    if self.verifier.expired(intent):
                        return await self._finish(ev, intent, error=f"retry window expired: {e}")
                    await asyncio.sleep(delay)
                    delay = min(delay * 2, 1.0)
                except AuthError as e:
                    await self.c.db.execute("update broker_accounts set enabled = false where id = $1",
                                            intent.broker_account_id)
                    await self.pool.evict(intent.broker_account_id)
                    return await self._finish(ev, intent, error=f"auth: {e}")
                except (ConnectorError, KeyError) as e:
                    return await self._finish(ev, intent, error=str(e))

    async def _finish(self, ev: Event, intent: TradeIntent, *, result=None, error: str | None = None) -> None:
        ok = result is not None and result.ok and error is None
        latency = None
        if intent.posted_at:
            latency = int((datetime.now(timezone.utc) - intent.posted_at).total_seconds() * 1000)
        tr = TradeResult(
            intent_id=intent.intent_id, user_id=intent.user_id, broker_account_id=intent.broker_account_id,
            connector="paper" if intent.mode == "paper" else intent.connector, mode=intent.mode,
            action=intent.action, status="executed" if ok else "failed", signal_id=intent.signal_id,
            channel_id=intent.channel_id, source_message_id=intent.source_message_id, symbol=intent.symbol,
            side=intent.side, ticket=(result.ticket if result else None) or intent.ticket,
            volume=result.volume if result and result.volume else intent.volume,
            price=result.price if result else None, sl=intent.sl, tp=intent.tp, tp_index=intent.tp_index,
            closed_volume=intent.volume if intent.action == "close" else None,
            profit=result.profit if result else None,
            error=error or (result.error if result and not result.ok else None), latency_ms=latency)
        await self.c.db.execute(
            "insert into orders (id, intent_id, user_id, broker_account_id, action, status, ticket, symbol, side, "
            "volume, price, error, latency_ms, trace_id) values ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14) "
            "on conflict (intent_id) do nothing",
            new_id("ord"), intent.intent_id, intent.user_id, intent.broker_account_id, intent.action, tr.status,
            tr.ticket, tr.symbol, tr.side, tr.volume, tr.price, tr.error, latency, ev.trace_id)
        await self.c.bus.publish(Topic.TRADE_EXECUTED if ok else Topic.TRADE_FAILED, tr, trace_id=ev.trace_id)
        self.c.log.info("trade " + tr.status, extra=ctx(trace_id=ev.trace_id, intent_id=intent.intent_id,
                                                       action=intent.action, ticket=tr.ticket, error=tr.error,
                                                       latency_ms=latency))

    # ------------------------------------------------------------------ snapshots
    async def snapshot_once(self) -> None:
        rows = await self.c.db.fetch(
            "select b.id, b.user_id, b.symbol_map, bool_or(r.mode = 'live') as any_live "
            "from broker_accounts b join copy_rules r on r.broker_account_id = b.id "
            "where b.enabled and r.enabled and r.mode in ('paper','live') group by b.id")
        ttl = int(self.c.settings.router_snapshot_interval_s * 3)
        for r in rows:
            mode = "live" if r["any_live"] else "paper"
            try:
                async with self.pool.lock(r["id"]):
                    conn = await self.pool.get(r["id"], mode)
                    symbols = list((r["symbol_map"] or {}).values()) or list(self.catalog.symbols)
                    info = await conn.account_info(symbols)
                    positions = await conn.positions()
            except Exception as e:
                self.c.log.warning("snapshot failed", extra=ctx(account=r["id"], error=repr(e)))
                await self.pool.evict(r["id"])
                continue
            await self.c.redis.set(f"{self.p}:acct:{r['id']}:info", info.model_dump_json(), ex=ttl)
            await self.c.bus.publish(Topic.ACCOUNT_SNAPSHOT, AccountSnapshot(
                broker_account_id=r["id"], user_id=r["user_id"], info=info, positions=positions))

    async def snapshot_loop(self) -> None:
        while not self.c.stop.is_set():
            t0 = time.monotonic()
            with contextlib.suppress(Exception):
                await self.snapshot_once()
            wait = max(self.c.settings.router_snapshot_interval_s - (time.monotonic() - t0), 1)
            with contextlib.suppress(asyncio.TimeoutError):
                await asyncio.wait_for(self.c.stop.wait(), wait)

    async def on_config(self, ev: Event) -> None:
        if ev.payload.get("entity") == "broker_account":
            await self.pool.evict(ev.payload.get("entity_id"))


async def main(c: Context) -> None:
    router = ExecutionRouter(c)
    await router.snapshot_once()
    await asyncio.gather(
        c.bus.subscribe([Topic.TRADE_INTENT], router.handle, consumer=c.consumer, claim_idle_ms=2000),
        c.bus.broadcast([Topic.CONFIG_CHANGED], router.on_config),
        router.snapshot_loop(),
    )
