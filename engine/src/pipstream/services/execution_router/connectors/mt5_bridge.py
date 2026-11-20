"""Self-hosted MT5 via a Wine/Docker bridge that exposes the official `MetaTrader5` Python
package over RPyC (the pattern used by mt5linux / MetaTrader5 docker images; one terminal,
one logged-in account per bridge container).

Credentials (encrypted in broker_accounts): {host, port, login, password, server,
magic?, deviation?}. The RPyC client is synchronous, so calls run in a worker thread and are
serialised per account.

VERIFY before live use: the bridge image you pick (see docker-compose `mt5` profile) must
serve `rpyc.classic` with MetaTrader5 importable; adapt `_open_session` if it differs.
"""
from __future__ import annotations

import asyncio
from typing import Any

from ....common.events import AccountInfo, Position, SymbolSpec, TradeIntent
from .base import AuthError, ConnectorError, Credentials, Health, OrderResult, RetryableError
from .registry import register

# MetaTrader5 retcodes worth retrying (requote, price changed, timeout, connection)
_RETRY = {10004, 10020, 10021, 10012, 10031}
_DONE = {10008, 10009, 10010}


@register("mt5_bridge")
class MT5BridgeConnector:
    name = "mt5_bridge"

    def __init__(self, *, mt5_factory=None, **_):
        self._factory = mt5_factory or self._open_session
        self.mt5: Any = None
        self._conn = None
        self._lock = asyncio.Lock()
        self.magic = 770077
        self.deviation = 20

    @staticmethod
    def _open_session(host: str, port: int):
        import rpyc
        conn = rpyc.classic.connect(host, int(port))
        return conn, conn.modules.MetaTrader5

    async def _call(self, fn, *a, **kw):
        async with self._lock:
            try:
                return await asyncio.wait_for(asyncio.to_thread(fn, *a, **kw), timeout=10)
            except (asyncio.TimeoutError, EOFError, ConnectionError, OSError) as e:
                raise RetryableError(f"mt5 bridge: {e!r}") from e

    async def connect(self, creds: Credentials) -> None:
        self.magic = int(creds.get("magic", self.magic))
        self.deviation = int(creds.get("deviation", self.deviation))
        try:
            self._conn, self.mt5 = await asyncio.to_thread(self._factory, creds["host"], creds.get("port", 18812))
        except Exception as e:
            raise RetryableError(f"mt5 bridge unreachable: {e!r}") from e
        ok = await self._call(self.mt5.initialize, login=int(creds["login"]), password=creds["password"],
                              server=creds["server"])
        if not ok:
            raise AuthError(f"mt5 initialize failed: {await self._call(self.mt5.last_error)}")

    # ------------------------------------------------------------------ helpers
    async def _filling(self, symbol: str) -> int:
        info = await self._call(self.mt5.symbol_info, symbol)
        if info is None:
            raise ConnectorError(f"unknown symbol {symbol}")
        fm = int(getattr(info, "filling_mode", 0))
        if fm & 1:
            return self.mt5.ORDER_FILLING_FOK
        if fm & 2:
            return self.mt5.ORDER_FILLING_IOC
        return self.mt5.ORDER_FILLING_RETURN

    def _check(self, res) -> OrderResult:
        if res is None:
            raise RetryableError("order_send returned None")
        code = int(res.retcode)
        if code in _DONE:
            return OrderResult(ok=True, ticket=str(res.order or res.deal), price=float(res.price or 0) or None,
                               volume=float(res.volume or 0) or None, raw={"retcode": code})
        if code in _RETRY:
            raise RetryableError(f"mt5 retcode {code}: {res.comment}")
        raise ConnectorError(f"mt5 retcode {code}: {res.comment}")

    # ------------------------------------------------------------------ trading
    async def open(self, intent: TradeIntent) -> OrderResult:
        m = self.mt5
        await self._call(m.symbol_select, intent.symbol, True)
        req: dict[str, Any] = {"symbol": intent.symbol, "volume": float(intent.volume),
                               "deviation": self.deviation, "magic": self.magic,
                               "comment": f"ps:{intent.source_message_id}:{intent.tp_index or 0}"[:31],
                               "type_time": m.ORDER_TIME_GTC,
                               "type_filling": await self._filling(intent.symbol)}
        if intent.sl:
            req["sl"] = float(intent.sl)
        if intent.tp:
            req["tp"] = float(intent.tp)
        buy = intent.side == "buy"
        if intent.order_type == "market":
            tick = await self._call(m.symbol_info_tick, intent.symbol)
            req.update(action=m.TRADE_ACTION_DEAL, type=m.ORDER_TYPE_BUY if buy else m.ORDER_TYPE_SELL,
                       price=float(tick.ask if buy else tick.bid))
        else:
            t = {("buy", "limit"): m.ORDER_TYPE_BUY_LIMIT, ("sell", "limit"): m.ORDER_TYPE_SELL_LIMIT,
                 ("buy", "stop"): m.ORDER_TYPE_BUY_STOP, ("sell", "stop"): m.ORDER_TYPE_SELL_STOP}
            req.update(action=m.TRADE_ACTION_PENDING, type=t[(intent.side, intent.order_type)],
                       price=float(intent.price))
        return self._check(await self._call(m.order_send, req))

    async def modify(self, ticket: str, sl: float | None, tp: float | None) -> OrderResult:
        m = self.mt5
        pos = await self._call(m.positions_get, ticket=int(ticket))
        if not pos:
            raise ConnectorError(f"position {ticket} not found")
        p = pos[0]
        req = {"action": m.TRADE_ACTION_SLTP, "position": int(ticket), "symbol": p.symbol,
               "sl": float(sl if sl is not None else p.sl), "tp": float(tp if tp is not None else p.tp)}
        return self._check(await self._call(m.order_send, req))

    async def close(self, ticket: str, volume: float | None = None) -> OrderResult:
        m = self.mt5
        pos = await self._call(m.positions_get, ticket=int(ticket))
        if not pos:
            raise ConnectorError(f"position {ticket} not found")
        p = pos[0]
        is_buy = p.type == m.POSITION_TYPE_BUY
        tick = await self._call(m.symbol_info_tick, p.symbol)
        req = {"action": m.TRADE_ACTION_DEAL, "position": int(ticket), "symbol": p.symbol,
               "volume": float(min(volume or p.volume, p.volume)),
               "type": m.ORDER_TYPE_SELL if is_buy else m.ORDER_TYPE_BUY,
               "price": float(tick.bid if is_buy else tick.ask), "deviation": self.deviation,
               "magic": self.magic, "type_filling": await self._filling(p.symbol)}
        res = self._check(await self._call(m.order_send, req))
        return res.model_copy(update={"ticket": ticket})

    async def positions(self) -> list[Position]:
        m = self.mt5
        out = []
        for p in await self._call(m.positions_get) or []:
            if int(p.magic) != self.magic:
                continue  # never touch trades the user opened by hand
            out.append(Position(ticket=str(p.ticket), symbol=p.symbol,
                                side="buy" if p.type == m.POSITION_TYPE_BUY else "sell",
                                volume=float(p.volume), open_price=float(p.price_open),
                                sl=float(p.sl) or None, tp=float(p.tp) or None, profit=float(p.profit)))
        return out

    async def account_info(self, symbols: list[str] | None = None) -> AccountInfo:
        m = self.mt5
        a = await self._call(m.account_info)
        if a is None:
            raise RetryableError("account_info unavailable")
        specs = {}
        for sym in symbols or []:
            i = await self._call(m.symbol_info, sym)
            if i is None:
                continue
            specs[sym] = SymbolSpec(point=float(i.point), tick_size=float(i.trade_tick_size),
                                    tick_value=float(i.trade_tick_value),
                                    contract_size=float(i.trade_contract_size), volume_min=float(i.volume_min),
                                    volume_max=float(i.volume_max), volume_step=float(i.volume_step))
        return AccountInfo(balance=float(a.balance), equity=float(a.equity), currency=a.currency, symbols=specs)

    async def health(self) -> Health:
        try:
            ti = await self._call(self.mt5.terminal_info)
            return Health(ok=bool(ti and ti.connected), detail="terminal connected" if ti else "no terminal")
        except Exception as e:
            return Health(ok=False, detail=repr(e))

    async def disconnect(self) -> None:
        if self.mt5 is not None:
            try:
                await self._call(self.mt5.shutdown)
            except Exception:
                pass
        if self._conn is not None:
            self._conn.close()
