"""Paper trading connector: simulated fills, state kept in Redis (survives restarts).
Used for mandatory paper mode on new channels and for the week-2 dry runs. Fills at the
intent price, else the reference price from the signal, else the cached quote."""
from __future__ import annotations


from redis.asyncio import Redis

from ....common.events import AccountInfo, Position, SymbolSpec, TradeIntent
from ...parser.engine import SymbolCatalog
from .base import ConnectorError, Credentials, Health, OrderResult
from .registry import register


@register("paper")
class PaperConnector:
    name = "paper"

    def __init__(self, *, redis: Redis, account_id: str, prefix: str = "ps",
                 catalog: SymbolCatalog | None = None, **_):
        self.r = redis
        self.k = f"{prefix}:paper:{account_id}"
        self.qk = f"{prefix}:quote"
        self.catalog = catalog or SymbolCatalog.load()
        self.start_balance = 10_000.0
        self.currency = "USD"

    async def connect(self, creds: Credentials) -> None:
        self.start_balance = float(creds.get("starting_balance", self.start_balance))
        self.currency = creds.get("currency", self.currency)
        await self.r.set(f"{self.k}:balance", self.start_balance, nx=True)

    async def _quote(self, symbol: str) -> float | None:
        v = await self.r.get(f"{self.qk}:{symbol}")
        return float(v) if v else None

    async def open(self, intent: TradeIntent) -> OrderResult:
        price = intent.price or intent.ref_price or await self._quote(intent.symbol)
        if price is None and intent.options is not None:
            price = 0.0  # options are priced by stake, not by quote
        if price is None:
            raise ConnectorError("paper: no price to fill at")
        if not intent.volume or intent.volume <= 0:
            raise ConnectorError("paper: volume must be > 0")
        ticket = str(await self.r.incr(f"{self.k}:seq"))
        pos = Position(ticket=ticket, symbol=intent.symbol, side=intent.side or "buy", volume=intent.volume,
                       open_price=price, sl=intent.sl, tp=intent.tp, profit=0.0)
        await self.r.hset(f"{self.k}:positions", ticket, pos.model_dump_json())
        return OrderResult(ok=True, ticket=ticket, price=price, volume=intent.volume)

    async def _get(self, ticket: str) -> Position:
        raw = await self.r.hget(f"{self.k}:positions", ticket)
        if not raw:
            raise ConnectorError(f"paper: no open position {ticket}")
        return Position.model_validate_json(raw)

    async def modify(self, ticket: str, sl: float | None, tp: float | None) -> OrderResult:
        pos = await self._get(ticket)
        pos = pos.model_copy(update={"sl": sl if sl is not None else pos.sl,
                                     "tp": tp if tp is not None else pos.tp})
        await self.r.hset(f"{self.k}:positions", ticket, pos.model_dump_json())
        return OrderResult(ok=True, ticket=ticket, price=pos.open_price, volume=pos.volume)

    async def close(self, ticket: str, volume: float | None = None) -> OrderResult:
        pos = await self._get(ticket)
        vol = min(volume or pos.volume, pos.volume)
        px = await self._quote(pos.symbol) or pos.open_price
        info = self.catalog.info(pos.symbol)
        cs = info.contract_size if info else 1.0
        sign = 1 if pos.side == "buy" else -1
        profit = round((px - pos.open_price) * sign * vol * cs, 2)
        await self.r.incrbyfloat(f"{self.k}:balance", profit)
        remaining = round(pos.volume - vol, 8)
        if remaining <= 0:
            await self.r.hdel(f"{self.k}:positions", ticket)
        else:
            await self.r.hset(f"{self.k}:positions", ticket,
                              pos.model_copy(update={"volume": remaining}).model_dump_json())
        return OrderResult(ok=True, ticket=ticket, price=px, volume=vol, profit=profit)

    async def positions(self) -> list[Position]:
        raw = await self.r.hgetall(f"{self.k}:positions")
        return [Position.model_validate_json(v) for v in raw.values()]

    async def account_info(self, symbols: list[str] | None = None) -> AccountInfo:
        bal = float(await self.r.get(f"{self.k}:balance") or self.start_balance)
        specs = {}
        for sym, info in self.catalog.symbols.items():
            if symbols and sym not in symbols:
                continue
            # USD-quoted approximation; real brokers report tick_value via account_info()
            specs[sym] = SymbolSpec(point=info.point, tick_size=info.point,
                                    tick_value=info.point * info.contract_size,
                                    contract_size=info.contract_size)
        return AccountInfo(balance=bal, equity=bal, currency=self.currency, symbols=specs)

    async def health(self) -> Health:
        return Health(ok=True, detail="paper")

    async def disconnect(self) -> None:
        return None


