"""EA bridge: the customer's own MT4 or MT5 terminal runs the PipStream Expert Advisor
(ea/PipStream.mq4, ea/PipStream.mq5), which checks in with the web app about once a second.

    executor --XADD--> {p}:acct:{id}:ea:cmd   (only the executor can write commands)
    web app  --XREVRANGE-> hands unexpired, unanswered commands to the EA on each check-in
    EA       places / modifies / closes, reports the result on the next check-in
    web app  --SET--> {p}:eares:{id}:r:{cmd_id}, :state (balance, positions), :specs, :seen
    executor --GET--> waits for the result, reads state/specs for sizing and snapshots

The web app can read commands and write results, but Redis ACLs stop it from creating a
command, so a web-app bug cannot place a trade. No broker password is involved at all.
"""
from __future__ import annotations

import asyncio
import json
import time

from ....common.events import AccountInfo, Position, SymbolSpec, TradeIntent
from .base import ConnectorError, Credentials, Health, NoAnswerError, NotSentError, OrderResult, RetryableError
from .registry import register

MAGIC = 770077
OFFLINE_AFTER_S = 15       # no check-in for this long -> terminal treated as offline
RESULT_GRACE_S = 4.0       # wait this long past a command's expiry for a late answer


@register("ea_bridge")
class EABridgeConnector:
    name = "ea_bridge"

    def __init__(self, *, redis=None, account_id: str = "", prefix: str = "ps", sleep=asyncio.sleep,
                 clock=time.time, ttl_s: float = 6.0, **_):
        self.redis, self.account_id, self.p = redis, account_id, prefix
        self._sleep, self._clock, self.ttl_s = sleep, clock, ttl_s
        self._queued: set[str] = set()

    def _k(self, suffix: str) -> str:
        return f"{self.p}:eares:{self.account_id}:{suffix}"

    async def connect(self, creds: Credentials) -> None:
        return None  # nothing to log in to: the EA connects to us

    async def _seen_age(self) -> float | None:
        v = await self.redis.get(self._k("seen"))
        return None if v is None else self._clock() - float(v)

    async def _require_online(self) -> None:
        age = await self._seen_age()
        if age is None:
            raise NotSentError("MetaTrader terminal has never checked in: is the PipStream EA running?")
        if age > OFFLINE_AFTER_S:
            raise NotSentError(f"MetaTrader terminal offline (last check-in {int(age)} s ago)")

    async def _run(self, cmd_id: str, cmd: dict) -> OrderResult:
        await self._require_online()
        rkey = self._k(f"r:{cmd_id}")
        expires = self._clock() + self.ttl_s
        if cmd_id not in self._queued:  # a router retry reuses the queued command, never doubles it
            cmd = {**cmd, "id": cmd_id, "expires_ms": int(expires * 1000)}
            await self.redis.xadd(f"{self.p}:acct:{self.account_id}:ea:cmd", {"c": json.dumps(cmd)},
                                  maxlen=500, approximate=True)
            self._queued.add(cmd_id)
        deadline = expires + RESULT_GRACE_S
        while self._clock() < deadline:
            raw = await self.redis.get(rkey)
            if raw:
                self._queued.discard(cmd_id)
                r = json.loads(raw)
                return OrderResult(ok=bool(r.get("ok")), ticket=str(r["ticket"]) if r.get("ticket") else None,
                                   price=r.get("price") or None, volume=r.get("volume") or None,
                                   error=None if r.get("ok") else (r.get("error") or "rejected by MetaTrader"),
                                   raw={k: r[k] for k in ("retcode", "new_ticket") if k in r})
            await self._sleep(0.05)
        self._queued.discard(cmd_id)
        raise NoAnswerError("MetaTrader terminal did not answer in time")

    @staticmethod
    def _cid(intent: TradeIntent) -> str:
        return intent.intent_id.replace("_", "")[-20:]

    async def open(self, intent: TradeIntent) -> OrderResult:
        return await self._run(self._cid(intent), {
            "action": "open", "symbol": intent.symbol, "side": intent.side, "order_type": intent.order_type,
            "volume": intent.volume, "price": intent.price, "sl": intent.sl, "tp": intent.tp,
            "comment": f"ps{intent.source_message_id}t{intent.tp_index or 0}"[:24]})

    async def modify(self, ticket: str, sl: float | None, tp: float | None) -> OrderResult:
        cid = f"m{ticket}{int(self._clock() * 1000)}"[-20:]
        return await self._run(cid, {"action": "modify", "ticket": str(ticket), "sl": sl, "tp": tp})

    async def close(self, ticket: str, volume: float | None = None) -> OrderResult:
        cid = f"c{ticket}{int(self._clock() * 1000)}"[-20:]
        res = await self._run(cid, {"action": "close", "ticket": str(ticket), "volume": volume})
        return res.model_copy(update={"ticket": str(ticket)})

    async def ping(self) -> OrderResult:
        """Round trip to the terminal without trading (connection test)."""
        return await self._run(f"p{int(self._clock() * 1000)}"[-20:], {"action": "ping"})

    async def state(self) -> dict:
        return await self._state()

    async def _state(self) -> dict:
        await self._require_online()
        raw = await self.redis.get(self._k("state"))
        if not raw:
            raise RetryableError("EA has not sent its account state yet")
        return json.loads(raw)

    async def positions(self) -> list[Position]:
        out = []
        for p in (await self._state()).get("positions", []):
            if int(p.get("magic") or 0) != MAGIC:
                continue  # never touch trades the customer opened by hand
            out.append(Position(ticket=str(p["ticket"]), symbol=p["symbol"], side=p["side"],
                                volume=float(p["volume"]), open_price=float(p.get("open_price") or 0),
                                sl=p.get("sl") or None, tp=p.get("tp") or None, profit=p.get("profit")))
        return out

    async def account_info(self, symbols: list[str] | None = None) -> AccountInfo:
        st = await self._state()
        raw = await self.redis.get(self._k("specs"))
        all_specs = json.loads(raw) if raw else {}
        wanted = set(symbols or [])
        specs = {}
        for name, s in all_specs.items():
            # canonical XAUUSD also matches broker variants like XAUUSDm / XAUUSD.r
            if wanted and not any(name == w or (name.upper().startswith(w.upper()) and len(name) <= len(w) + 4)
                                  for w in wanted):
                continue
            try:
                specs[name] = SymbolSpec(point=s["point"], tick_size=s["tick_size"], tick_value=s["tick_value"],
                                         contract_size=s.get("contract_size", 1), volume_min=s.get("volume_min", 0.01),
                                         volume_max=s.get("volume_max", 100), volume_step=s.get("volume_step", 0.01))
            except (KeyError, TypeError, ValueError):
                continue
        return AccountInfo(balance=float(st.get("balance") or 0), equity=float(st.get("equity") or 0),
                           currency=st.get("currency") or "USD", symbols=specs)

    async def health(self) -> Health:
        age = await self._seen_age()
        if age is None:
            return Health(ok=False, detail="EA never checked in")
        return Health(ok=age <= OFFLINE_AFTER_S, detail=f"last check-in {age:.0f} s ago")

    async def disconnect(self) -> None:
        return None
