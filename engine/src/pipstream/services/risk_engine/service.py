"""Rules / Risk Engine: signal.parsed -> signed trade.intent (one per broker account and TP leg).

For every enabled copy rule on the signal's channel:
  entitlement (plan, kill switches) -> filters (symbols, hours, confidence) ->
  price guard (already past TP1 / slippage) -> limits (open trades, daily loss) ->
  sizing (fixed | risk_pct | multiplier | stake) -> multi-TP split -> sign (Ed25519, 5 s TTL)

Follow-ups (modify / close) are resolved to concrete tickets through the positions table
(written by the position tracker), so "move SL to entry" touches exactly the trades opened
from the original message.

Duplicates: a rule copies one post once. An edit of a post that was already copied only moves
SL/TP (never opens again), and the same channel + symbol + side + levels posted again within
the duplicate window (30 min by default) is skipped as `duplicate_signal`.

Modes: shadow = record "would have traded" only (week 1); paper = paper connector;
live = the account's real connector (demo accounts during the experiment).

Customer commands (user.command, from the web app / the PipStream bot):
  close_all       one signed close intent per open trade of the customer
  approve_signal  a rule with limits.confirm_first holds each new signal (ps:pending:<id>, 5 min)
                  and asks the customer (risk.limit_hit awaiting_confirm); yes re-plans it now,
                  with fresh prices and limits, and copies it
  reject_signal   drops the held signal
"""
from __future__ import annotations

import hashlib
import time
from datetime import datetime, timedelta, timezone

import json

from ...common.events import (AccountInfo, Event, ParsedSignal, RiskLimitHit, SymbolSpec, TradeIntent,
                              Topic, UserCommand)
from ...common.ids import new_id
from ...common.log import ctx
from ...common.service import Context
from ...common.signing import IntentSigner
from ..parser import selftest
from ..parser.engine import SymbolCatalog
from .filters import check_filters, entry_passed
from .sizing import SizingError, finalize, resolve_symbol, risk_lots, round_down, split_tps

RULES_SQL = """
select r.*, b.connector, b.symbol_map, k.kind as connector_kind, u.kill_switch as user_kill,
       bs.status as billing_status, p.allow_live
from copy_rules r
join broker_accounts b on b.id = r.broker_account_id and (b.enabled or r.mode = 'shadow')
join connectors k on k.name = b.connector and k.enabled
join users u on u.id = r.user_id and u.status = 'active'
left join billing_subscriptions bs on bs.user_id = r.user_id
left join plans p on p.id = bs.plan_id
where r.channel_id = $1 and r.enabled
"""


class Skip(Exception):
    def __init__(self, limit: str, detail: str = ""):
        super().__init__(limit)
        self.limit, self.detail = limit, detail


class RiskEngine:
    def __init__(self, c: Context):
        self.c = c
        s = c.settings
        if not s.intent_signing_key:
            raise SystemExit("PS_INTENT_SIGNING_KEY is required")
        self.signer = IntentSigner(s.intent_signing_key, s.intent_key_id, s.intent_ttl_ms)
        self.catalog = SymbolCatalog.load(s.symbols_file)
        self.p = s.stream_prefix
        self.checks = s.risk_checks
        if not self.checks:
            c.log.warning("risk checks are OFF (PS_RISK_CHECKS=false): every parsed signal is traded; "
                          "kill switches and sizing still apply")

    # ------------------------------------------------------------------ entry
    async def dispatch(self, ev: Event) -> None:
        if ev.topic == Topic.USER_COMMAND:
            return await self.command(ev)
        return await self.handle(ev)

    async def handle(self, ev: Event) -> None:
        sig: ParsedSignal = ev.parse()
        if await self.c.redis.get(f"{self.p}:killswitch:global") == "1":
            self.c.log.warning("global kill switch on; signal ignored", extra=ctx(signal_id=sig.signal_id))
            return
        rules = await self.c.db.fetch(RULES_SQL, sig.channel_id)
        if sig.test is not None:
            return await self._self_test(sig, [dict(r) for r in rules if r["user_id"] == sig.test.user_id], ev.trace_id)
        for rule in rules:
            try:
                intents = await self.plan(sig, dict(rule))
            except Skip as s:
                await self._limit(sig, rule, s, ev.trace_id)
                continue
            if intents and self._asks_first(sig, rule):
                await self._hold(sig, dict(rule), ev.trace_id)
                continue
            if rule["mode"] == "shadow":
                await self.c.db.execute("insert into shadow_trades (id, copy_rule_id, signal_id, payload) "
                                        "values ($1,$2,$3,$4)", new_id("shd"), rule["id"], sig.signal_id,
                                        [i.model_dump(mode="json") for i in intents])
                self.c.log.info("shadow: would have traded", extra=ctx(trace_id=ev.trace_id, rule=rule["id"],
                                                                      legs=len(intents)))
                continue
            for intent in intents:
                await self._emit(intent, ev.trace_id)

    # ------------------------------------------------------------------ ask first / customer commands
    @staticmethod
    def _asks_first(sig: ParsedSignal, rule) -> bool:
        """New trades only: follow-ups (SL/TP moves, closes) to trades already open always go through."""
        return bool((rule["limits"] or {}).get("confirm_first")) and rule["mode"] != "shadow" \
            and sig.kind in ("open", "options") and sig.source.edit_version == 0

    async def _hold(self, sig: ParsedSignal, rule: dict, trace_id: str) -> None:
        pid = new_id("pnd")
        ttl = int(self.c.settings.risk_confirm_ttl_s)
        await self.c.redis.set(f"{self.p}:pending:{pid}", json.dumps({
            "signal": sig.model_dump(mode="json", by_alias=True), "rule_id": rule["id"], "user_id": rule["user_id"],
            "trace_id": trace_id, "at": time.time()}), ex=ttl)
        # the expiry note: a marker that outlives the held signal by a minute
        await self.c.redis.set(f"{self.p}:pending:{pid}:exp", rule["user_id"], ex=ttl + 3600)
        self.c.log.info("signal held for the customer's yes", extra=ctx(trace_id=trace_id, rule=rule["id"], pending=pid))
        await self.c.bus.publish(Topic.RISK_LIMIT_HIT, RiskLimitHit(
            user_id=rule["user_id"], broker_account_id=rule["broker_account_id"], copy_rule_id=rule["id"],
            signal_id=sig.signal_id, limit="awaiting_confirm", detail=pid), trace_id=trace_id)

    async def command(self, ev: Event) -> None:
        cmd: UserCommand = ev.parse()
        if cmd.action == "close_all":
            return await self._close_all(cmd, ev.trace_id)
        if not cmd.pending_id:
            return
        key = f"{self.p}:pending:{cmd.pending_id}"
        raw = await self.c.redis.get(key)
        held = json.loads(raw) if raw else None
        if held and held["user_id"] != cmd.user_id:
            self.c.log.warning("pending signal answered by another user; ignored", extra=ctx(pending=cmd.pending_id))
            return
        if not held or not await self.c.redis.delete(key):  # gone, or a second tap already took it
            if held is None and await self.c.redis.get(f"{key}:exp") == cmd.user_id and cmd.action == "approve_signal":
                await self.c.redis.delete(f"{key}:exp")
                await self.c.bus.publish(Topic.RISK_LIMIT_HIT, RiskLimitHit(
                    user_id=cmd.user_id, broker_account_id="", copy_rule_id="", signal_id="",
                    limit="confirm_expired", detail="the signal waited 5 minutes for your yes"), trace_id=ev.trace_id)
            return
        await self.c.redis.delete(f"{key}:exp")
        sig = ParsedSignal.model_validate(held["signal"])
        trace = held.get("trace_id") or ev.trace_id
        rows = [dict(r) for r in await self.c.db.fetch(RULES_SQL, sig.channel_id) if r["id"] == held["rule_id"]]
        if cmd.action == "reject_signal" or not rows:
            if rows:
                await self._limit(sig, rows[0], Skip("confirm_rejected", "you skipped it"), trace)
            return
        rule = rows[0]
        try:
            intents = await self.plan(sig, rule, claim=False)  # fresh prices, limits and kill switch
        except Skip as s:
            return await self._limit(sig, rule, s, trace)
        if rule["mode"] == "shadow":
            return
        for intent in intents:
            await self._emit(intent, trace)
        self.c.log.info("held signal copied after the customer's yes", extra=ctx(trace_id=trace, rule=rule["id"], legs=len(intents)))

    async def _close_all(self, cmd: UserCommand, trace_id: str) -> None:
        """Every open trade of this customer, on every enabled account, closed in full."""
        rows = await self.c.db.fetch(
            "select p.*, b.connector, coalesce("
            " (select r.id from copy_rules r where r.user_id = p.user_id and r.channel_id = p.channel_id"
            "   and r.broker_account_id = p.broker_account_id order by r.enabled desc, r.created_at limit 1),"
            " (select i.copy_rule_id from trade_intents i where i.signal_id = p.signal_id"
            "   and i.broker_account_id = p.broker_account_id order by i.created_at limit 1)) as rule_id "
            "from positions p join broker_accounts b on b.id = p.broker_account_id and b.enabled "
            "where p.user_id = $1 and p.status = 'open'", cmd.user_id)
        n = 0
        for p in rows:
            if not p["rule_id"]:
                self.c.log.warning("close all: no copy rule for position", extra=ctx(position=p["id"]))
                continue
            intent = TradeIntent(
                idempotency_key=f"{cmd.command_id}:close:{p['broker_account_id']}:{p['ticket']}", user_id=p["user_id"],
                broker_account_id=p["broker_account_id"], copy_rule_id=p["rule_id"], connector=p["connector"],
                mode="paper" if p["connector"] == "paper" else "live", action="close", signal_id=p["signal_id"],
                channel_id=p["channel_id"], source_message_id=p["source_message_id"], symbol=p["symbol"], side=p["side"],
                volume=float(p["volume"]) if p["volume"] is not None else None, ticket=p["ticket"], tp_index=p["tp_index"],
                expires_at=self.signer.expiry())
            await self._emit(intent, trace_id)
            n += 1
        self.c.log.info("close all", extra=ctx(trace_id=trace_id, user=cmd.user_id, closing=n))

    async def _self_test(self, sig: ParsedSignal, rules: list[dict], trace_id: str) -> None:
        """Channel self-test: what this customer's rule would do with the post. Records the plan
        for the app; places it only when the customer asked (and never in watch-only mode)."""
        p, t = self.p, sig.test
        if await self.c.redis.get(f"{p}:killswitch:global") == "1":
            await selftest.update(self.c.redis, p, t.test_id, state="planned", done=True,
                                  plan={"status": "skipped", "limit": "global_pause", "detail": "copying is paused for everyone"})
            return
        if not rules:
            await selftest.update(self.c.redis, p, t.test_id, state="planned", done=True, plan={"status": "no_rule"})
            return
        rule = sorted(rules, key=lambda r: r["mode"] == "shadow")[0]  # the app keeps one rule per channel
        base = {"mode": rule["mode"], "broker_account_id": rule["broker_account_id"], "connector": rule["connector"]}
        place = t.place and rule["mode"] != "shadow"
        try:
            intents = await self.plan(sig, rule, claim=place)
        except Skip as s:
            await selftest.update(self.c.redis, p, t.test_id, state="planned", done=True,
                                  plan={**base, "status": "skipped", "limit": s.limit, "detail": s.detail})
            return
        legs = [{"action": i.action, "symbol": i.symbol, "side": i.side, "order_type": i.order_type, "price": i.price,
                 "ref_price": i.ref_price, "volume": i.volume, "sl": i.sl, "tp": i.tp, "tp_index": i.tp_index,
                 "options": i.options.model_dump() if i.options else None} for i in intents]
        if place:
            for intent in intents:
                await self._emit(intent, trace_id)
        await selftest.update(self.c.redis, p, t.test_id, state="planned", done=True,
                              plan={**base, "status": "placed" if place else "would_copy", "legs": legs,
                                    "signal_id": sig.signal_id if place else None})
        self.c.log.info("self-test planned", extra=ctx(trace_id=trace_id, rule=rule["id"], legs=len(legs), placed=place))

    async def _limit(self, sig, rule, s: Skip, trace_id: str) -> None:
        self.c.log.info("rule skipped", extra=ctx(trace_id=trace_id, rule=rule["id"], limit=s.limit, detail=s.detail))
        await self.c.bus.publish(Topic.RISK_LIMIT_HIT, RiskLimitHit(
            user_id=rule["user_id"], broker_account_id=rule["broker_account_id"], copy_rule_id=rule["id"],
            signal_id=sig.signal_id, limit=s.limit, detail=s.detail), trace_id=trace_id)

    async def _emit(self, intent: TradeIntent, trace_id: str) -> None:
        # Re-sign at emit time so the 5 s window starts now.
        intent = self.signer.sign(intent.model_copy(update={"expires_at": self.signer.expiry()}))
        inserted = await self.c.db.fetchval(
            "insert into trade_intents (id, idempotency_key, copy_rule_id, user_id, broker_account_id, signal_id, "
            "action, payload, expires_at, trace_id) values ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10) "
            "on conflict (idempotency_key) do nothing returning id",
            intent.intent_id, intent.idempotency_key, intent.copy_rule_id, intent.user_id,
            intent.broker_account_id, intent.signal_id, intent.action, intent.model_dump(mode="json"),
            intent.expires_at, trace_id)
        if inserted is None:
            return  # already emitted for this signal/rule/leg (re-delivery)
        await self.c.bus.publish(Topic.TRADE_INTENT, intent, trace_id=trace_id)

    # ------------------------------------------------------------------ planning
    async def plan(self, sig: ParsedSignal, rule: dict, *, claim: bool = True) -> list[TradeIntent]:
        """The intents for one rule. `claim` records the post as copied for the duplicate guard
        (off for a self-test that only shows what would happen)."""
        if rule["user_kill"] or await self.c.redis.get(f"{self.p}:killswitch:user:{rule['user_id']}") == "1":
            raise Skip("kill_switch", "user kill switch on")
        if self.checks:
            if rule["billing_status"] not in ("trialing", "active"):
                raise Skip("no_subscription", str(rule["billing_status"]))
            if rule["mode"] == "live" and not rule["allow_live"]:
                raise Skip("plan_no_live", "plan allows paper only")
            if reason := check_filters(sig, rule["filters"] or {}):
                raise Skip(reason)
        if sig.kind in ("modify", "close"):
            return await self._followup(sig, rule)
        if sig.source.edit_version > 0:
            existing = await self._open_positions(rule["broker_account_id"], sig.channel_id, sig.source.message_id)
            if existing:
                return self._edit_to_modify(sig, rule, existing)
            if await self._already_copied(sig, rule):
                raise Skip("duplicate_edit", "this post was already copied; an edit never opens it again")
            if sig.source.posted_at and datetime.now(timezone.utc) - sig.source.posted_at > timedelta(seconds=120):
                raise Skip("stale_edit", "edited signal older than 120 s with no trade")
        intents = await (self._options(sig, rule) if sig.kind == "options" else self._open(sig, rule))
        if claim:
            await self._claim(sig, rule)
        return intents

    # ------------------------------------------------------------------ duplicates
    @staticmethod
    def fingerprint(sig: ParsedSignal) -> str:
        """Same channel, symbol, side, entry, SL and TPs (or the same options contract) = same signal."""
        def n(x) -> str:
            return "" if x is None else f"{float(x):.6g}"
        e, o = sig.entry, sig.options
        parts = [sig.channel_id, sig.kind, (sig.symbol or "").upper(), sig.side or "",
                 e.type if e else "", n(e.price) if e else "", n(e.from_) if e else "", n(e.to) if e else "",
                 n(sig.sl), ",".join(n(t) for t in sig.tps), n(sig.sl_pips), ",".join(n(t) for t in sig.tp_pips),
                 f"{o.contract}:{o.duration}{o.duration_unit}" if o else ""]
        return hashlib.sha256("|".join(parts).encode()).hexdigest()[:24]

    def _dup_window_s(self, rule: dict) -> int:
        mins = (rule.get("limits") or {}).get("duplicate_window_min")
        return int(float(mins) * 60) if mins is not None else int(self.c.settings.risk_duplicate_window_s)

    async def _claim(self, sig: ParsedSignal, rule: dict) -> None:
        """First copy of this signal for this rule within the window wins; a repeat is skipped.
        A re-delivery of the same signal (same signal_id) passes: trade_intents keys stop doubles."""
        window = self._dup_window_s(rule)
        if window <= 0:
            return
        key = f"{self.p}:dup:{rule['id']}:{self.fingerprint(sig)}"
        if await self.c.redis.set(key, f"{sig.signal_id}|{time.time():.0f}", nx=True, ex=window):
            return
        prev_id, _, at = (await self.c.redis.get(key) or "").partition("|")
        if prev_id in ("", sig.signal_id):
            return
        ago = max(int((time.time() - float(at or time.time())) / 60), 0)
        what = " ".join(x for x in (sig.symbol, sig.side) if x)
        raise Skip("duplicate_signal", f"the same {what} signal was copied "
                                       + ("just now" if ago < 1 else f"{ago} min ago"))

    async def _already_copied(self, sig: ParsedSignal, rule: dict) -> bool:
        """Did an earlier version of this post already open something for this rule (and not fail)?"""
        return bool(await self.c.db.fetchval(
            "select exists (select 1 from trade_intents i join signals s on s.id = i.signal_id "
            "where i.copy_rule_id = $1 and s.channel_id = $2 and s.message_id = $3 and i.action = 'open' "
            "and i.signal_id <> $4 and not exists (select 1 from orders o where o.intent_id = i.id "
            "and o.status = 'failed'))", rule["id"], sig.channel_id, sig.source.message_id, sig.signal_id))

    def _base(self, sig: ParsedSignal, rule: dict, **kw) -> dict:
        kw.setdefault("max_lot", float(rule["max_lot"]))
        return dict(user_id=rule["user_id"], broker_account_id=rule["broker_account_id"], copy_rule_id=rule["id"],
                    connector=rule["connector"], mode="paper" if rule["mode"] in ("paper", "shadow") else "live",
                    signal_id=sig.signal_id, channel_id=sig.channel_id, source_message_id=sig.source.message_id,
                    posted_at=sig.source.posted_at,
                    expires_at=self.signer.expiry(), **kw)

    async def _account(self, rule: dict) -> AccountInfo:
        raw = await self.c.redis.get(f"{self.p}:acct:{rule['broker_account_id']}:info")
        if raw:
            return AccountInfo.model_validate_json(raw)
        if rule["mode"] == "shadow":
            bal = float((rule["limits"] or {}).get("shadow_balance", 10_000))
            return AccountInfo(balance=bal, equity=bal)
        raise Skip("no_account_snapshot", "router has not reported this account yet")

    def _default_spec(self, symbol: str) -> SymbolSpec | None:
        i = self.catalog.info(symbol)
        if not i:
            return None
        return SymbolSpec(point=i.point, tick_size=i.point, tick_value=i.point * i.contract_size,
                          contract_size=i.contract_size)

    async def _open(self, sig: ParsedSignal, rule: dict) -> list[TradeIntent]:
        if rule["connector_kind"] == "options":
            raise Skip("unsupported_signal_kind", "CFD signal on an options-only account")
        limits = rule["limits"] or {}
        info = await self._account(rule)
        broker_sym = resolve_symbol(sig.symbol, rule["symbol_map"] or {}, set(info.symbols) or None)
        if broker_sym is None:
            raise Skip("symbol_unavailable", sig.symbol)
        spec = info.symbols.get(broker_sym) or self._default_spec(sig.symbol)
        if spec is None:
            raise Skip("symbol_unavailable", f"no spec for {broker_sym}")
        q = await self.c.redis.get(f"{self.p}:quote:{broker_sym}")
        quote = float(q) if q else None
        if self.checks and (reason := entry_passed(sig, quote, limits.get("max_entry_slippage_points"), spec.point)):
            raise Skip(reason)

        ref = (sig.entry.reference_price() if sig.entry else None) or quote
        pip = (self.catalog.info(sig.symbol).pip if self.catalog.info(sig.symbol) else spec.point * 10)
        sl, tps = sig.sl, list(sig.tps)
        sign = 1 if sig.side == "buy" else -1
        if sl is None and sig.sl_pips is not None and ref is not None:
            sl = round(ref - sign * sig.sl_pips * pip, 6)
        if not tps and sig.tp_pips and ref is not None:
            tps = [round(ref + sign * t * pip, 6) for t in sig.tp_pips]
        if self.checks and limits.get("require_sl", True) and sl is None:
            raise Skip("missing_sl", "rule requires a stop loss")

        if self.checks and (mx := limits.get("max_open_trades")) is not None:
            n = await self.c.db.fetchval("select count(*) from positions where broker_account_id=$1 and status='open'",
                                         rule["broker_account_id"])
            if n >= int(mx):
                raise Skip("max_open_trades", f"{n} open")
        if self.checks and (mdl := limits.get("max_daily_loss")) is not None:
            pnl = await self.c.db.fetchval(
                "select coalesce(sum(profit),0) from positions where broker_account_id=$1 and status='closed' "
                "and closed_at >= date_trunc('day', now())", rule["broker_account_id"])
            if float(pnl) <= -abs(float(mdl)):
                raise Skip("max_daily_loss", f"today {float(pnl):.2f}")

        mode = rule["sizing_mode"]
        if mode == "fixed":
            vol = float(rule["fixed_lots"] or spec.volume_min)
        elif mode == "multiplier":
            vol = float(rule["fixed_lots"] or spec.volume_min) * float(rule["multiplier"] or 1)
        elif mode == "risk_pct":
            if (sl is None or ref is None) and not self.checks:
                vol = float(spec.volume_min)  # checks off: no SL/price to size from, trade the minimum
            elif sl is None or ref is None:
                raise Skip("sizing", "risk sizing needs entry/market price and SL")
            else:
                try:
                    vol = risk_lots(info.balance, float(rule["risk_pct"]), ref, sl, spec)
                except SizingError as e:
                    raise Skip("sizing", str(e)) from e
        else:
            raise Skip("sizing", f"sizing mode {mode} not valid for CFD signals")
        try:
            total = finalize(vol, spec, float(rule["max_lot"]))
        except SizingError as e:
            raise Skip("below_min_lot", str(e)) from e

        legs = split_tps(total, max(len(tps), 1), spec, rule["tp_split"])
        order_type = sig.entry.type if sig.entry and sig.entry.type in ("limit", "stop") else "market"
        price = sig.entry.price if order_type != "market" else None
        out = []
        for i, lots in enumerate(legs):
            tp = tps[i] if i < len(tps) else None
            out.append(TradeIntent(**self._base(
                sig, rule, idempotency_key=f"{sig.signal_id}:{rule['id']}:open:{i}", action="open",
                symbol=broker_sym, side=sig.side, order_type=order_type, price=price, ref_price=ref,
                volume=lots, sl=sl, tp=tp, tp_index=i + 1 if tp is not None else None)))
        return out

    async def _options(self, sig: ParsedSignal, rule: dict) -> list[TradeIntent]:
        if rule["connector_kind"] not in ("options", "paper") and rule["mode"] == "live":
            raise Skip("unsupported_signal_kind", "options signal on a CFD account")
        limits = rule["limits"] or {}
        stake = float(rule["stake"] or sig.options.stake or 0)
        if rule["sizing_mode"] == "multiplier" and sig.options.stake:
            stake = sig.options.stake * float(rule["multiplier"] or 1)
        if stake <= 0:
            raise Skip("sizing", "no stake configured")
        if (ms := limits.get("max_stake")) is not None and stake > float(ms):
            stake = float(ms)
        sym = (rule["symbol_map"] or {}).get(sig.symbol, sig.symbol)
        return [TradeIntent(**self._base(sig, rule, idempotency_key=f"{sig.signal_id}:{rule['id']}:options:0",
                                         action="open", symbol=sym, side=sig.side, volume=stake,
                                         options=sig.options.model_copy(update={"stake": stake}),
                                         max_lot=None))]

    # ------------------------------------------------------------------ follow-ups
    async def _open_positions(self, account_id: str, channel_id: str, message_id: int | None,
                              symbol: str | None = None) -> list[dict]:
        if message_id is None:  # no reply: latest signal on this channel with open trades
            message_id = await self.c.db.fetchval(
                "select source_message_id from positions where broker_account_id=$1 and channel_id=$2 "
                "and status='open' and ($3::text is null or symbol like $3 || '%') "
                "order by opened_at desc limit 1", account_id, channel_id, symbol)
            if message_id is None:
                return []
        rows = await self.c.db.fetch(
            "select * from positions where broker_account_id=$1 and channel_id=$2 and source_message_id=$3 "
            "and status='open' order by tp_index nulls last, opened_at", account_id, channel_id, message_id)
        return [dict(r) for r in rows]

    async def _followup(self, sig: ParsedSignal, rule: dict) -> list[TradeIntent]:
        positions = await self._open_positions(rule["broker_account_id"], sig.channel_id,
                                               sig.source.reply_to_id, sig.symbol)
        if not positions:
            raise Skip("no_open_positions", f"nothing to {sig.kind} for message {sig.source.reply_to_id}")
        out = []
        if sig.kind == "modify":
            for p in positions:
                sl = float(p["open_price"]) if sig.move_sl_to_entry else sig.new_sl
                out.append(self._ticket_intent(sig, rule, p, "modify", sl=sl, tp=sig.new_tp))
            return out
        frac = sig.close_fraction or 1.0
        if frac >= 1.0:
            return [self._ticket_intent(sig, rule, p, "close", volume=float(p["volume"])) for p in positions]
        if len(positions) > 1:  # multi-TP: close the nearest-TP legs fully
            n = max(1, round(len(positions) * frac))
            return [self._ticket_intent(sig, rule, p, "close", volume=float(p["volume"])) for p in positions[:n]]
        p = positions[0]
        spec = self._default_spec(sig.symbol or "") or SymbolSpec(point=0.01, tick_size=0.01, tick_value=1)
        vol = round_down(float(p["volume"]) * frac, spec.volume_step)
        if vol < spec.volume_min:
            raise Skip("below_min_lot", "partial close smaller than minimum lot")
        return [self._ticket_intent(sig, rule, p, "close", volume=vol)]

    def _edit_to_modify(self, sig: ParsedSignal, rule: dict, positions: list[dict]) -> list[TradeIntent]:
        out = []
        for p in positions:
            idx = (p["tp_index"] or 1) - 1
            tp = sig.tps[idx] if idx < len(sig.tps) else None
            if (sig.sl is not None and float(p["sl"] or 0) != sig.sl) or (tp is not None and float(p["tp"] or 0) != tp):
                out.append(self._ticket_intent(sig, rule, p, "modify", sl=sig.sl, tp=tp))
        if not out:
            raise Skip("edit_no_change", "edit did not change SL/TP")
        return out

    def _ticket_intent(self, sig, rule, p: dict, action: str, **kw) -> TradeIntent:
        return TradeIntent(**self._base(
            sig, rule, idempotency_key=f"{sig.signal_id}:{rule['id']}:{action}:{p['ticket']}", action=action,
            symbol=p["symbol"], side=p["side"], ticket=p["ticket"], tp_index=p["tp_index"], **kw))


async def main(c: Context) -> None:
    engine = RiskEngine(c)
    await c.bus.subscribe([Topic.SIGNAL_PARSED, Topic.USER_COMMAND], engine.dispatch, consumer=c.consumer)
