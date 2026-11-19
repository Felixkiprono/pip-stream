"""Connector contract tests against fakes of the MT5 module and the Deriv WebSocket."""
import asyncio
import json
from datetime import timedelta
from types import SimpleNamespace as NS

import pytest

from pipstream.common.events import OptionsSpec, TradeIntent, utcnow
from pipstream.services.execution_router.connectors import registry
from pipstream.services.execution_router.connectors.base import AuthError, Connector, RetryableError


def intent(**kw):
    d = dict(idempotency_key="k", user_id="u", broker_account_id="b", copy_rule_id="r", connector="x", mode="live",
             action="open", signal_id="s", channel_id="c", source_message_id=7, symbol="XAUUSD", side="buy",
             volume=0.1, sl=2340.0, tp=2360.0, tp_index=1, expires_at=utcnow() + timedelta(seconds=5))
    d.update(kw)
    return TradeIntent(**d)


class FakeMT5:
    TRADE_ACTION_DEAL, TRADE_ACTION_PENDING, TRADE_ACTION_SLTP = 1, 5, 6
    ORDER_TYPE_BUY, ORDER_TYPE_SELL, ORDER_TYPE_BUY_LIMIT, ORDER_TYPE_SELL_LIMIT = 0, 1, 2, 3
    ORDER_TYPE_BUY_STOP, ORDER_TYPE_SELL_STOP = 4, 5
    ORDER_FILLING_FOK, ORDER_FILLING_IOC, ORDER_FILLING_RETURN = 0, 1, 2
    ORDER_TIME_GTC, POSITION_TYPE_BUY = 0, 0

    def __init__(self, login_ok=True, retcodes=None):
        self.login_ok, self.sent, self.retcodes = login_ok, [], list(retcodes or [])
        self.pos = {}

    def initialize(self, **kw): return self.login_ok
    def last_error(self): return (-6, "Authorization failed")
    def symbol_select(self, s, on): return True
    def symbol_info(self, s): return NS(filling_mode=2, point=0.01, trade_tick_size=0.01, trade_tick_value=1.0,
                                        trade_contract_size=100, volume_min=0.01, volume_max=50, volume_step=0.01)
    def symbol_info_tick(self, s): return NS(ask=2349.5, bid=2349.2)
    def account_info(self): return NS(balance=1000.0, equity=1001.0, currency="USD")
    def terminal_info(self): return NS(connected=True)
    def shutdown(self): pass

    def order_send(self, req):
        self.sent.append(req)
        code = self.retcodes.pop(0) if self.retcodes else 10009
        if code == 10009 and req["action"] == self.TRADE_ACTION_DEAL and "position" not in req:
            t = 1000 + len(self.sent)
            self.pos[t] = NS(ticket=t, symbol=req["symbol"], type=req["type"], volume=req["volume"],
                             price_open=req["price"], sl=req.get("sl", 0), tp=req.get("tp", 0), profit=0.0, magic=req["magic"])
            return NS(retcode=code, order=t, deal=t, price=req["price"], volume=req["volume"], comment="done")
        return NS(retcode=code, order=0, deal=0, price=req.get("price", 0), volume=req.get("volume", 0), comment="x")

    def positions_get(self, ticket=None):
        return [self.pos[ticket]] if ticket else list(self.pos.values())


async def test_mt5_bridge_open_modify_close():
    fake = FakeMT5()
    c = registry.create("mt5_bridge", mt5_factory=lambda h, p: (None, fake))
    assert isinstance(c, Connector)
    await c.connect({"host": "bridge", "login": 1, "password": "p", "server": "Demo"})
    res = await c.open(intent())
    assert res.ok and res.price == 2349.5
    req = fake.sent[-1]
    assert req["type"] == fake.ORDER_TYPE_BUY and req["sl"] == 2340 and req["type_filling"] == fake.ORDER_FILLING_IOC
    assert req["comment"] == "ps:7:1"
    assert (await c.modify(res.ticket, 2349.5, None)).ok and fake.sent[-1]["action"] == fake.TRADE_ACTION_SLTP
    assert (await c.close(res.ticket, 0.05)).ok and fake.sent[-1]["type"] == fake.ORDER_TYPE_SELL
    assert [p.ticket for p in await c.positions()] == [res.ticket]
    info = await c.account_info(["XAUUSD"])
    assert info.balance == 1000 and info.symbols["XAUUSD"].tick_value == 1.0


async def test_mt5_bridge_errors():
    c = registry.create("mt5_bridge", mt5_factory=lambda h, p: (None, FakeMT5(login_ok=False)))
    with pytest.raises(AuthError):
        await c.connect({"host": "h", "login": 1, "password": "bad", "server": "s"})
    c = registry.create("mt5_bridge", mt5_factory=lambda h, p: (None, FakeMT5(retcodes=[10004])))
    await c.connect({"host": "h", "login": 1, "password": "p", "server": "s"})
    with pytest.raises(RetryableError):  # requote
        await c.open(intent())


class FakeWS:
    """Minimal Deriv API: authorize, proposal, buy, sell, balance, portfolio, ping."""

    def __init__(self):
        self.q, self.sent = asyncio.Queue(), []

    async def send(self, raw):
        m = json.loads(raw)
        self.sent.append(m)
        rid = m["req_id"]
        if "authorize" in m:
            r = {"authorize": {"currency": "USD"}} if m["authorize"] == "good" else \
                {"error": {"code": "InvalidToken", "message": "bad"}}
        elif "proposal" in m:
            r = {"proposal": {"id": "p1", "ask_price": m["amount"]}}
        elif "buy" in m:
            r = {"buy": {"contract_id": 555, "buy_price": m["price"], "longcode": "Win payout if ..."}}
        elif "sell" in m:
            r = {"sell": {"sold_for": 1.9}}
        elif "balance" in m:
            r = {"balance": {"balance": 10000, "currency": "USD"}}
        elif "portfolio" in m:
            r = {"portfolio": {"contracts": [{"contract_id": 555, "symbol": "R_75", "contract_type": "CALL", "buy_price": 1}]}}
        else:
            r = {"ping": "pong"}
        await self.q.put(json.dumps({**r, "req_id": rid}))

    def __aiter__(self):
        return self

    async def __anext__(self):
        return await self.q.get()

    async def close(self):
        pass


async def test_deriv_options_flow():
    ws = FakeWS()

    async def connect(url):
        assert "app_id=1089" in url
        return ws

    c = registry.create("deriv_options", ws_connect=connect)
    await c.connect({"token": "good", "app_id": "1089"})
    res = await c.open(intent(symbol="R_75", volume=1.0, sl=None, tp=None,
                              options=OptionsSpec(contract="rise", duration=5, duration_unit="t", stake=1.0)))
    assert res.ok and res.ticket == "555"
    prop = next(m for m in ws.sent if "proposal" in m)
    assert (prop["contract_type"], prop["duration"], prop["duration_unit"], prop["symbol"]) == ("CALL", 5, "t", "R_75")
    assert (await c.account_info()).balance == 10000
    assert (await c.positions())[0].ticket == "555"
    assert (await c.health()).ok
    await c.disconnect()


async def test_deriv_bad_token():
    ws = FakeWS()

    async def connect(url):
        return ws

    c = registry.create("deriv_options", ws_connect=connect)
    with pytest.raises(AuthError):
        await c.connect({"token": "nope", "app_id": "1"})
