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

    async def _provisioned(self, account_id: str, metaapi_id: str, platform: str) -> None:
        """MetaApi now holds the password: keep only its account id (sealed) and remember the id
        in symbol_map so the account can be deleted at MetaApi when the customer removes it."""
        await self.c.db.execute(
            "update broker_accounts set credentials_ciphertext = $2, "
            "symbol_map = symbol_map || jsonb_build_object('_metaapi_id', $3::text), updated_at = now() "
            "where id = $1", account_id, self.opener.seal({"account_id": metaapi_id, "platform": platform}),
            metaapi_id)
        self.c.log.info("metaapi account provisioned", extra=ctx(account=account_id, metaapi_id=metaapi_id))

    async def get(self, account_id: str, mode: str, *, setup: bool = False) -> Connector:
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
            extra = {}
            if name == "metaapi":
                async def on_prov(mid: str, platform: str, _acc=account_id) -> None:
                    await self._provisioned(_acc, mid, platform)
                # Trades never wait minutes for a terminal to start: only the background set-up
                # task (setup=True) creates the MetaApi account and waits for it to connect.
                extra = dict(settings=self.c.settings, on_provisioned=on_prov, provision=setup,
                             connect_timeout_s=self.c.settings.metaapi_connect_timeout_s if setup else 3.0)
            conn = registry.create(name, redis=self.c.redis, account_id=account_id,
                                   prefix=self.c.settings.stream_prefix, catalog=self.catalog,
                                   **{**extra, **self.factories.get(name, {})})
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
        self._tasks: set[asyncio.Task] = set()
        self._setting_up: set[str] = set()

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
                    # keys starting with "_" are account notes (login hint, platform, MetaApi id), not symbols
                    mapped = [v for k, v in (r["symbol_map"] or {}).items() if not k.startswith("_")]
                    symbols = mapped or list(self.catalog.symbols)
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
        if ev.payload.get("entity") == "broker_test":
            p = ev.payload
            self._spawn(self.run_test(p.get("entity_id"), str(p.get("test_id", "")), bool(p.get("order"))))
            return
        if ev.payload.get("entity") == "broker_account":
            account_id = ev.payload.get("entity_id")
            await self.pool.evict(account_id)
            if account_id:
                self._spawn(self.setup_metaapi(account_id))

    def _spawn(self, coro) -> None:
        t = asyncio.create_task(coro)
        self._tasks.add(t)
        t.add_done_callback(self._tasks.discard)

    async def run_test(self, account_id: str | None, test_id: str, order: bool) -> None:
        """Customer pressed "Test connection" (and maybe "Test order"): check the round trip to
        their broker or terminal and write each step to {p}:acct:{id}:test:{test_id} for the app.
        A test order is only placed on DEMO accounts, at the smallest size, and closed at once."""
        import json as _json
        if not account_id or not test_id.isalnum():
            return
        key = f"{self.p}:acct:{account_id}:test:{test_id}"
        steps: list[dict] = []
        out: dict = {"state": "running", "steps": steps}

        async def save(**kw) -> None:
            out.update(kw)
            await self.c.redis.set(key, _json.dumps(out, default=str), ex=600)

        async def step(name: str, ok: bool, detail: str = "", ms: float | None = None) -> None:
            steps.append({"name": name, "ok": ok, "detail": detail, "ms": None if ms is None else round(ms)})
            await save()

        await save()
        try:
            row = await self.c.db.fetchrow("select connector, enabled from broker_accounts where id = $1", account_id)
            if row is None or not row["enabled"]:
                await step("Account", False, "This broker account was removed")
                return await save(state="done", ok=False)
            name = row["connector"]
            async with self.pool.lock(account_id):
                t0 = time.monotonic()
                try:
                    conn = await self.pool.get(account_id, "paper" if name == "paper" else "live")
                except Exception as e:
                    await step("Connect", False, str(e))
                    return await save(state="done", ok=False)
                if name == "ea_bridge":
                    t1 = time.monotonic()
                    res = await conn.ping()
                    if res.ok or (res.error or "").startswith("unknown action"):
                        note = "" if res.ok else "terminal answered; download the latest EA for faster tests"
                        await step("Terminal answered", True, note, (time.monotonic() - t1) * 1000)
                    else:
                        await step("Terminal answered", False, res.error or "no answer")
                        return await save(state="done", ok=False)
                else:
                    h = await conn.health()
                    await step("Broker connection", h.ok, h.detail, (time.monotonic() - t0) * 1000)
                    if not h.ok:
                        return await save(state="done", ok=False)
                info = await conn.account_info([])
                await step("Account", True, f"balance {info.balance:,.2f} {info.currency}")
                state = {}
                if name == "ea_bridge":
                    state = await conn.state()
                    allowed = bool(state.get("trade_allowed"))
                    await step("Algo Trading on", allowed, "" if allowed else "turn on Algo Trading / AutoTrading in the toolbar")
                    if not allowed:
                        return await save(state="done", ok=False)
                if order:
                    if await self.c.redis.get(f"{self.p}:killswitch:global") == "1":
                        await step("Test order", False, "copying is stopped by the kill switch")
                        return await save(state="done", ok=False)
                    if name != "paper" and not state.get("demo"):
                        await step("Test order", False, "test orders run on demo accounts only")
                        return await save(state="done", ok=False)
                    await self._test_order(conn, account_id, step)
            await save(state="done", ok=all(s["ok"] for s in steps))
        except Exception as e:
            self.c.log.warning("broker test failed", extra=ctx(account=account_id, error=repr(e)))
            await step("Test", False, str(e) or repr(e))
            await save(state="done", ok=False)

    async def _test_order(self, conn, account_id: str, step) -> None:
        from ...common.events import TradeIntent, utcnow
        from datetime import timedelta
        specs = (await conn.account_info(list(self.catalog.symbols))).symbols
        pick = next((s for want in ("EURUSD", "XAUUSD", "GBPUSD", "USDJPY") for s in specs
                     if s.upper().startswith(want)), next(iter(specs), None))
        if pick is None:
            return await step("Test order", False, "no tradable symbol reported by the terminal")
        vol = specs[pick].volume_min
        intent = TradeIntent(idempotency_key=f"test-{account_id}-{time.time_ns()}", user_id="test",
                             broker_account_id=account_id, copy_rule_id="test", connector="test", mode="live",
                             action="open", signal_id="test", channel_id="test", source_message_id=0, symbol=pick,
                             side="buy", volume=vol, tp_index=0, expires_at=utcnow() + timedelta(seconds=10))
        t0 = time.monotonic()
        res = await conn.open(intent)
        if not res.ok or not res.ticket:
            return await step("Test order opened", False, res.error or "rejected")
        await step("Test order opened", True, f"bought {vol:g} {pick} at {res.price or '?'} (ticket {res.ticket})",
                   (time.monotonic() - t0) * 1000)
        t1 = time.monotonic()
        res2 = await conn.close(res.ticket)
        await step("Test order closed", res2.ok, res2.error or f"ticket {res.ticket} closed", (time.monotonic() - t1) * 1000)

    async def setup_metaapi(self, account_id: str) -> None:
        """A MetaApi account was added or removed: create and start it at MetaApi (minutes, in
        the background) so the first signal finds it connected, or delete it there."""
        row = await self.c.db.fetchrow("select connector, enabled, credentials_ciphertext, symbol_map "
                                       "from broker_accounts where id = $1", account_id)
        if row is None or row["connector"] != "metaapi":
            return
        if not row["enabled"]:
            mid = (row["symbol_map"] or {}).get("_metaapi_id")
            if mid:
                from .connectors.metaapi import remove_account
                try:
                    await remove_account(self.c.settings, mid,
                                         transport=self.pool.factories.get("metaapi", {}).get("transport"))
                    self.c.log.info("metaapi account removed", extra=ctx(account=account_id, metaapi_id=mid))
                except Exception as e:
                    self.c.log.warning("metaapi remove failed", extra=ctx(account=account_id, error=repr(e)))
            return
        if account_id in self._setting_up:
            return
        self._setting_up.add(account_id)
        try:
            async with self.pool.lock(account_id):
                await self.pool.get(account_id, "live", setup=True)
        except Exception as e:
            self.c.log.warning("metaapi set-up did not finish", extra=ctx(account=account_id, error=repr(e)))
            await self.pool.evict(account_id)
        finally:
            self._setting_up.discard(account_id)

    async def resume_setups(self) -> None:
        """After a restart, finish setting up MetaApi accounts that were added but never connected."""
        rows = await self.c.db.fetch("select id from broker_accounts where enabled and connector = 'metaapi'")
        for r in rows:
            self._spawn(self.setup_metaapi(r["id"]))


async def main(c: Context) -> None:
    router = ExecutionRouter(c)
    await router.resume_setups()
    await router.snapshot_once()
    await asyncio.gather(
        c.bus.subscribe([Topic.TRADE_INTENT], router.handle, consumer=c.consumer, claim_idle_ms=2000),
        c.bus.broadcast([Topic.CONFIG_CHANGED], router.on_config),
        router.snapshot_loop(),
    )
