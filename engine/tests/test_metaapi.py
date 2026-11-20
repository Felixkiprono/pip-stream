"""MetaApi connector against a stand-in for MetaApi's REST API (provisioning + client)."""
import json
from datetime import timedelta

import httpx
import pytest

from pipstream.common.events import TradeIntent, utcnow
from pipstream.common.settings import Settings
from pipstream.services.execution_router.connectors import registry
from pipstream.services.execution_router.connectors.base import AuthError, Connector, ConnectorError, RetryableError
from pipstream.services.execution_router.connectors.metaapi import MAGIC, remove_account


def intent(**kw):
    d = dict(idempotency_key="k", user_id="u", broker_account_id="b", copy_rule_id="r", connector="metaapi",
             mode="live", action="open", signal_id="s", channel_id="c", source_message_id=7, symbol="XAUUSDm",
             side="buy", volume=0.1, sl=2340.0, tp=2360.0, tp_index=1, expires_at=utcnow() + timedelta(seconds=5))
    d.update(kw)
    return TradeIntent(**d)


class FakeMetaApi:
    """Just enough of MetaApi: create (202 once, then 201), deploy, read, trade, positions, symbols."""

    def __init__(self, *, auth_error=False, server_missing=False, detect_202=1, connect_after=1):
        self.auth_error, self.server_missing = auth_error, server_missing
        self.detect_202, self.connect_after = detect_202, connect_after
        self.created: list[dict] = []
        self.txids: list[str] = []
        self.trades: list[dict] = []
        self.reads = 0
        self.deleted: list[str] = []
        self.positions = {}

    def __call__(self, req: httpx.Request) -> httpx.Response:
        assert req.headers["auth-token"] == "tok"
        p, m = req.url.path, req.method
        host = req.url.host
        if host.startswith("mt-provisioning"):
            if m == "POST" and p == "/users/current/accounts":
                self.txids.append(req.headers["transaction-id"])
                if self.auth_error:
                    return httpx.Response(400, json={"error": "ValidationError", "message": "bad", "details": "E_AUTH"})
                if self.server_missing:
                    return httpx.Response(400, json={"error": "ValidationError", "message": "x", "details": {
                        "code": "E_SRV_NOT_FOUND", "serversByBrokers": {"Exness": ["Exness-MT4Trial7"]}}})
                if self.detect_202 > 0:
                    self.detect_202 -= 1
                    return httpx.Response(202, json={"message": "detecting"}, headers={"retry-after": "1"})
                self.created.append(json.loads(req.content))
                return httpx.Response(201, json={"id": "ma1", "state": "UNDEPLOYED"})
            if m == "POST" and p.endswith("/deploy"):
                return httpx.Response(204)
            if m == "GET" and p == "/users/current/accounts/ma1":
                self.reads += 1
                cs = "CONNECTED" if self.reads > self.connect_after else "DISCONNECTED"
                return httpx.Response(200, json={"_id": "ma1", "state": "DEPLOYED", "connectionStatus": cs,
                                                 "region": "london"})
            if m == "DELETE":
                self.deleted.append(p.rsplit("/", 1)[-1])
                return httpx.Response(204)
            return httpx.Response(404, json={"message": "nope"})
        assert host == "mt-client-api-v1.london.agiliumtrade.ai", host
        base = "/users/current/accounts/ma1"
        if m == "POST" and p == base + "/trade":
            body = json.loads(req.content)
            self.trades.append(body)
            assert len(body.get("comment", "")) + len(body.get("clientId", "")) <= 26
            if body["actionType"] in ("ORDER_TYPE_BUY", "ORDER_TYPE_SELL"):
                pid = str(100 + len(self.trades))
                self.positions[pid] = {"id": pid, "symbol": body["symbol"], "volume": body["volume"],
                                       "type": "POSITION_TYPE_BUY" if body["actionType"].endswith("BUY")
                                       else "POSITION_TYPE_SELL", "openPrice": 2349.4,
                                       "stopLoss": body.get("stopLoss"), "takeProfit": body.get("takeProfit"),
                                       "profit": 0.0, "magic": body["magic"]}
                return httpx.Response(200, json={"numericCode": 10009, "stringCode": "TRADE_RETCODE_DONE",
                                                 "orderId": pid, "positionId": pid})
            return httpx.Response(200, json={"numericCode": 10009, "stringCode": "TRADE_RETCODE_DONE"})
        if m == "GET" and p.startswith(base + "/positions/"):
            return httpx.Response(200, json=self.positions[p.rsplit("/", 1)[-1]])
        if m == "GET" and p == base + "/positions":
            manual = {"id": "9", "symbol": "EURUSD", "type": "POSITION_TYPE_BUY", "volume": 1, "openPrice": 1.1,
                      "magic": 0}
            return httpx.Response(200, json=list(self.positions.values()) + [manual])
        if m == "GET" and p == base + "/account-information":
            return httpx.Response(200, json={"balance": 1000, "equity": 1002.5, "currency": "USD"})
        if m == "GET" and p == base + "/symbols":
            return httpx.Response(200, json=["EURUSDm", "XAUUSDm", "US30m"])
        if m == "GET" and p == base + "/symbols/XAUUSDm/specification":
            return httpx.Response(200, json={"symbol": "XAUUSDm", "tickSize": 0.001, "digits": 3, "contractSize": 100,
                                             "minVolume": 0.01, "maxVolume": 200, "volumeStep": 0.01})
        if m == "GET" and p == base + "/symbols/XAUUSDm/current-price":
            return httpx.Response(200, json={"bid": 2349.1, "ask": 2349.4, "lossTickValue": 0.1})
        return httpx.Response(404, json={"message": "not found"})


class FakeRedis:
    def __init__(self):
        self.kv = {}

    async def set(self, k, v, ex=None):
        self.kv[k] = v


async def _no_sleep(_s):
    return None


def make(fake, **kw):
    saved = {}

    async def on_prov(mid, platform):
        saved["id"], saved["platform"] = mid, platform

    redis = FakeRedis()
    deps = dict(settings=Settings(metaapi_token="tok"), redis=redis, account_id="bk1", prefix="ps",
                on_provisioned=on_prov, transport=httpx.MockTransport(fake), sleep=_no_sleep, provision=True,
                connect_timeout_s=30)
    deps.update(kw)
    return registry.create("metaapi", **deps), saved, redis


def status(redis):
    return json.loads(redis.kv["ps:acct:bk1:status"])


async def test_provision_then_trade_modify_close():
    fake = FakeMetaApi()
    c, saved, redis = make(fake)
    assert isinstance(c, Connector)
    await c.connect({"login": "51234567", "password": "pw", "server": "Exness-MT4Trial7", "platform": "mt4"})

    # 202 (broker detection) is retried with the SAME transaction id, then created and deployed
    assert len(fake.txids) == 2 and fake.txids[0] == fake.txids[1] and len(fake.txids[0]) == 32
    body = fake.created[0]
    assert body["platform"] == "mt4" and body["login"] == "51234567" and body["magic"] == MAGIC
    assert saved == {"id": "ma1", "platform": "mt4"}
    assert status(redis)["state"] == "connected"

    r = await c.open(intent())
    assert r.ok and r.ticket == "101" and r.price == 2349.4
    t = fake.trades[0]
    assert t["actionType"] == "ORDER_TYPE_BUY" and t["stopLoss"] == 2340.0 and t["takeProfit"] == 2360.0

    await c.modify("101", sl=2349.4, tp=None)
    assert fake.trades[-1] == {"actionType": "POSITION_MODIFY", "positionId": "101", "stopLoss": 2349.4}
    await c.close("101", volume=0.05)
    assert fake.trades[-1] == {"actionType": "POSITION_PARTIAL", "positionId": "101", "volume": 0.05}
    await c.close("101")
    assert fake.trades[-1] == {"actionType": "POSITION_CLOSE_ID", "positionId": "101"}

    # only our own trades are reported, never ones the customer opened by hand
    pos = await c.positions()
    assert [p.ticket for p in pos] == ["101"] and pos[0].side == "buy"

    info = await c.account_info(["XAUUSD", "GBPJPY"])
    assert info.balance == 1000 and info.equity == 1002.5
    assert list(info.symbols) == ["XAUUSDm"]  # broker suffix found; unknown symbol skipped
    spec = info.symbols["XAUUSDm"]
    assert spec.point == 0.001 and spec.tick_value == 0.1 and spec.contract_size == 100
    await c.disconnect()


async def test_reconnect_with_saved_account_id_does_not_provision():
    fake = FakeMetaApi(connect_after=0)
    c, saved, _ = make(fake, provision=False)
    await c.connect({"account_id": "ma1", "platform": "mt5"})
    assert fake.created == [] and saved == {}
    r = await c.open(intent(side="sell", tp=None))
    assert r.ok and fake.trades[0]["actionType"] == "ORDER_TYPE_SELL" and "takeProfit" not in fake.trades[0]


async def test_trades_never_create_accounts():
    fake = FakeMetaApi()
    c, _, _ = make(fake, provision=False)
    with pytest.raises(RetryableError, match="still being set up"):
        await c.connect({"login": "5", "password": "pw", "server": "s", "platform": "mt5"})
    assert fake.txids == []


async def test_wrong_password_and_unknown_server():
    c, _, redis = make(FakeMetaApi(auth_error=True))
    with pytest.raises(AuthError):
        await c.connect({"login": "5", "password": "bad", "server": "Exness-MT4Trial7", "platform": "mt4"})
    assert "Wrong account number or password" in status(redis)["detail"]

    c, _, redis = make(FakeMetaApi(server_missing=True))
    with pytest.raises(AuthError):
        await c.connect({"login": "5", "password": "pw", "server": "Exness-Trial", "platform": "mt4"})
    assert "Exness-MT4Trial7" in status(redis)["detail"]


async def test_still_connecting_is_retryable():
    c, _, redis = make(FakeMetaApi(connect_after=99), connect_timeout_s=0)
    with pytest.raises(RetryableError):
        await c.connect({"account_id": "ma1", "platform": "mt5"})
    assert status(redis)["state"] == "connecting"


async def test_missing_token():
    c, _, redis = make(FakeMetaApi(), settings=Settings())
    with pytest.raises(ConnectorError):
        await c.connect({"account_id": "ma1"})
    assert status(redis)["state"] == "error"


async def test_remove_account():
    fake = FakeMetaApi()
    await remove_account(Settings(metaapi_token="tok"), "ma1", transport=httpx.MockTransport(fake))
    assert fake.deleted == ["ma1"]


# ---------------------------------------------------------------- router integration (Postgres)
async def test_router_sets_up_and_removes_metaapi_account(acl_redis, test_db, keys):
    from pipstream.common.db import create_pool, migrate
    from pipstream.common.service import build_context, close_context
    from pipstream.common.vault import Opener, seal
    from pipstream.services.execution_router.service import ExecutionRouter

    pool = await create_pool(test_db)
    await migrate(pool)
    await pool.execute("insert into connectors (name, kind) values ('metaapi', 'cfd') on conflict do nothing")
    await pool.execute("insert into users (id, email, name) values ('u1', 'a@b.c', 'A')")
    sealed = seal({"login": "51234567", "password": "pw", "server": "Exness-MT4Trial7", "platform": "mt4"},
                  keys["executor_public_key"])
    await pool.execute("insert into broker_accounts (id, user_id, connector, label, credentials_ciphertext, "
                       "symbol_map) values ('bk1', 'u1', 'metaapi', 'MT4', $1, '{\"_login_hint\": \"567\"}')", sealed)

    fake = FakeMetaApi()
    s = Settings(redis_url=acl_redis["url"]("executor"), database_url=test_db, metaapi_token="tok", **keys)
    c = await build_context("executor", s)
    try:
        router = ExecutionRouter(c, factories={"metaapi": {"transport": httpx.MockTransport(fake),
                                                           "sleep": _no_sleep}})
        await router.setup_metaapi("bk1")
        assert len(fake.created) == 1

        row = await pool.fetchrow("select credentials_ciphertext, symbol_map from broker_accounts where id = 'bk1'")
        creds = Opener(keys["executor_private_key"]).open(row["credentials_ciphertext"])
        assert creds == {"account_id": "ma1", "platform": "mt4"}  # the password is gone
        sm = row["symbol_map"] if isinstance(row["symbol_map"], dict) else json.loads(row["symbol_map"])
        assert sm["_metaapi_id"] == "ma1" and sm["_login_hint"] == "567"
        st = json.loads(await c.redis.get("ps:acct:bk1:status"))
        assert st["state"] == "connected"

        # a second set-up (restart / config change) reuses the account instead of creating another
        await router.pool.evict("bk1")
        await router.setup_metaapi("bk1")
        assert len(fake.created) == 1

        # the customer removes it: deleted at MetaApi so it stops costing money
        await pool.execute("update broker_accounts set enabled = false, credentials_ciphertext = null where id = 'bk1'")
        await router.setup_metaapi("bk1")
        assert fake.deleted == ["ma1"]
    finally:
        await close_context(c)
        await pool.close()
