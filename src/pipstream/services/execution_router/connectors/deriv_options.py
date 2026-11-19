"""Deriv Options connector (rise/fall contracts on synthetic indices) for DEMO accounts.

Signals look like "V75 RISE 5 ticks stake $2": no SL/TP, sized by stake, fixed duration.

Transport: Deriv WebSocket API JSON messages (authorize / proposal / buy / sell /
portfolio / balance / ping), correlated by req_id. Credentials: {token, app_id,
ws_url?, currency?}. The default ws_url is the long-standing v3 endpoint.

VERIFY before use: the handoff notes Deriv's newer Options API (REST + OTP-authenticated
WebSocket at api.derivws.com). If your account uses that flow, implement `_DerivTransport.
open()` with the OTP URL; the rest of this connector stays the same.
"""
from __future__ import annotations

import asyncio
import contextlib
import itertools
import json
import time
from typing import Any

from ....common.events import AccountInfo, Position, TradeIntent
from .base import AuthError, ConnectorError, Credentials, Health, OrderResult, RetryableError
from .registry import register

DEFAULT_WS = "wss://ws.derivws.com/websockets/v3"
_CONTRACT = {"rise": "CALL", "fall": "PUT"}


class _DerivTransport:
    def __init__(self, url: str, connect=None):
        self.url = url
        self._connect = connect
        self.ws = None
        self._ids = itertools.count(1)
        self._waiters: dict[int, asyncio.Future] = {}
        self._reader: asyncio.Task | None = None

    async def open(self) -> None:
        if self._connect is None:
            import websockets
            self.ws = await websockets.connect(self.url, ping_interval=20, open_timeout=10)
        else:
            self.ws = await self._connect(self.url)
        self._reader = asyncio.create_task(self._read())

    async def _read(self) -> None:
        try:
            async for raw in self.ws:
                msg = json.loads(raw)
                fut = self._waiters.pop(msg.get("req_id"), None)
                if fut and not fut.done():
                    fut.set_result(msg)
        except Exception as e:
            for f in self._waiters.values():
                if not f.done():
                    f.set_exception(RetryableError(f"deriv ws closed: {e!r}"))
            self._waiters.clear()

    async def call(self, payload: dict[str, Any], timeout: float = 8.0) -> dict:
        if self.ws is None:
            raise RetryableError("deriv: not connected")
        rid = next(self._ids)
        fut = asyncio.get_running_loop().create_future()
        self._waiters[rid] = fut
        await self.ws.send(json.dumps({**payload, "req_id": rid}))
        try:
            msg = await asyncio.wait_for(fut, timeout)
        except asyncio.TimeoutError as e:
            self._waiters.pop(rid, None)
            raise RetryableError("deriv: timeout") from e
        if "error" in msg:
            code = msg["error"].get("code", "")
            text = f"deriv {code}: {msg['error'].get('message')}"
            if code in ("InvalidToken", "AuthorizationRequired"):
                raise AuthError(text)
            if code in ("RateLimit", "InvalidOfferings") or "price" in text.lower():
                raise RetryableError(text)
            raise ConnectorError(text)
        return msg

    async def close(self) -> None:
        if self._reader:
            self._reader.cancel()
        if self.ws is not None:
            with contextlib.suppress(Exception):
                await self.ws.close()


@register("deriv_options")
class DerivOptionsConnector:
    name = "deriv_options"

    def __init__(self, *, ws_connect=None, **_):
        self._ws_connect = ws_connect
        self.t: _DerivTransport | None = None
        self.currency = "USD"

    async def connect(self, creds: Credentials) -> None:
        url = f"{creds.get('ws_url', DEFAULT_WS)}?app_id={creds['app_id']}"
        self.t = _DerivTransport(url, self._ws_connect)
        await self.t.open()
        auth = await self.t.call({"authorize": creds["token"]})
        self.currency = auth.get("authorize", {}).get("currency") or creds.get("currency", "USD")

    async def open(self, intent: TradeIntent) -> OrderResult:
        if intent.options is None:
            raise ConnectorError("deriv_options only accepts options intents")
        o = intent.options
        stake = float(intent.volume or o.stake or 0)
        if stake <= 0:
            raise ConnectorError("stake must be > 0")
        prop = await self.t.call({"proposal": 1, "amount": stake, "basis": "stake",
                                  "contract_type": _CONTRACT[o.contract], "currency": self.currency,
                                  "duration": o.duration, "duration_unit": o.duration_unit,
                                  "symbol": intent.symbol})
        pid = prop["proposal"]["id"]
        buy = await self.t.call({"buy": pid, "price": stake})
        b = buy["buy"]
        return OrderResult(ok=True, ticket=str(b["contract_id"]), price=float(b.get("buy_price", stake)),
                           volume=stake, raw={"longcode": b.get("longcode")})

    async def modify(self, ticket: str, sl: float | None, tp: float | None) -> OrderResult:
        raise ConnectorError("rise/fall contracts cannot be modified")

    async def close(self, ticket: str, volume: float | None = None) -> OrderResult:
        res = await self.t.call({"sell": int(ticket), "price": 0})  # 0 = sell at market
        s = res["sell"]
        return OrderResult(ok=True, ticket=ticket, price=float(s.get("sold_for", 0)))

    async def positions(self) -> list[Position]:
        res = await self.t.call({"portfolio": 1})
        out = []
        for c in res.get("portfolio", {}).get("contracts", []):
            out.append(Position(ticket=str(c["contract_id"]), symbol=c.get("symbol", ""),
                                side="buy" if c.get("contract_type") == "CALL" else "sell",
                                volume=float(c.get("buy_price", 0)), open_price=float(c.get("buy_price", 0))))
        return out

    async def account_info(self, symbols: list[str] | None = None) -> AccountInfo:
        res = await self.t.call({"balance": 1})
        bal = float(res["balance"]["balance"])
        return AccountInfo(balance=bal, equity=bal, currency=res["balance"].get("currency", self.currency))

    async def health(self) -> Health:
        t0 = time.perf_counter()
        try:
            await self.t.call({"ping": 1}, timeout=3)
            return Health(ok=True, latency_ms=(time.perf_counter() - t0) * 1000)
        except Exception as e:
            return Health(ok=False, detail=repr(e))

    async def disconnect(self) -> None:
        if self.t:
            await self.t.close()
