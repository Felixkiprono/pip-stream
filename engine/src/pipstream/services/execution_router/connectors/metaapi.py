"""MetaApi connector: customer MT4 and MT5 accounts through MetaApi's hosted terminals
(metaapi.cloud), over its REST API. No Windows machine or local terminal needed.

Credentials (sealed to the executor):
  first time:  {login, password, server, platform: "mt4"|"mt5"}
  afterwards:  {account_id, platform}   (the password is dropped once MetaApi has it)

Flow on the first connect:
  1. POST /users/current/accounts on the provisioning API, with a 32-char transaction-id.
     202 means "broker settings detection in progress": wait Retry-After and repeat with the
     same transaction-id. E_AUTH -> AuthError, E_SRV_NOT_FOUND -> ConnectorError with hints.
  2. POST .../deploy, then the pool's `on_provisioned` hook re-seals the credentials as
     {account_id, platform} and records the id in broker_accounts.symbol_map._metaapi_id,
     so we can delete the MetaApi account when the customer removes it.
  3. Poll GET .../accounts/{id} until state DEPLOYED and connectionStatus CONNECTED.
     While that is pending, trading raises RetryableError("still connecting").

The connection state is written to redis `{prefix}:acct:{account_id}:status` as JSON
{state, detail, at} so the web app can show Connecting / Connected / the exact problem.

Docs: https://metaapi.cloud/docs/provisioning/ and https://metaapi.cloud/docs/client/
"""
from __future__ import annotations

import asyncio
import json
import secrets
import time
from typing import Any

import httpx

from ....common.events import AccountInfo, Position, SymbolSpec, TradeIntent
from .base import AuthError, ConnectorError, Credentials, Health, OrderResult, RetryableError
from .registry import register

PROVISIONING_URL = "https://mt-provisioning-api-v1.agiliumtrade.agiliumtrade.ai"
CLIENT_URL = "https://mt-client-api-v1.{region}.agiliumtrade.ai"
MAGIC = 770077
_DONE = {"TRADE_RETCODE_DONE", "TRADE_RETCODE_PLACED", "TRADE_RETCODE_DONE_PARTIAL"}
_RETRY = {"TRADE_RETCODE_REQUOTE", "TRADE_RETCODE_PRICE_CHANGED", "TRADE_RETCODE_TIMEOUT",
          "TRADE_RETCODE_CONNECTION", "TRADE_RETCODE_PRICE_OFF", "TRADE_RETCODE_TOO_MANY_REQUESTS"}


@register("metaapi")
class MetaApiConnector:
    name = "metaapi"

    def __init__(self, *, settings=None, redis=None, account_id: str = "", prefix: str = "ps",
                 on_provisioned=None, transport: httpx.AsyncBaseTransport | None = None,
                 sleep=asyncio.sleep, connect_timeout_s: float | None = None, provision: bool = False, **_):
        self.token = getattr(settings, "metaapi_token", None)
        self.prov_url = (getattr(settings, "metaapi_provisioning_url", None) or PROVISIONING_URL).rstrip("/")
        self.client_tpl = getattr(settings, "metaapi_client_url", None) or CLIENT_URL
        self.default_region = getattr(settings, "metaapi_region", None) or "new-york"
        self.connect_timeout_s = connect_timeout_s if connect_timeout_s is not None else \
            float(getattr(settings, "metaapi_connect_timeout_s", 240))
        self.redis, self.broker_account_id, self.prefix = redis, account_id, prefix
        self.on_provisioned = on_provisioned
        self.provision = provision  # only the background set-up task may create MetaApi accounts
        self._transport, self._sleep = transport, sleep
        self.http: httpx.AsyncClient | None = None
        self.account_id: str | None = None
        self.platform = "mt5"
        self.client_url: str | None = None
        self.connected = False
        self._symbols: list[str] | None = None

    # ------------------------------------------------------------------ plumbing
    async def _status(self, state: str, detail: str = "") -> None:
        if self.redis is None or not self.broker_account_id:
            return
        try:
            await self.redis.set(f"{self.prefix}:acct:{self.broker_account_id}:status",
                                 json.dumps({"state": state, "detail": detail, "at": int(time.time())}),
                                 ex=7 * 86400)
        except Exception:
            pass

    async def _req(self, method: str, url: str, **kw) -> httpx.Response:
        try:
            return await self.http.request(method, url, **kw)
        except httpx.TimeoutException as e:
            raise RetryableError(f"metaapi timeout: {e!r}") from e
        except httpx.TransportError as e:
            raise RetryableError(f"metaapi unreachable: {e!r}") from e

    @staticmethod
    def _err(r: httpx.Response) -> dict:
        try:
            body = r.json()
            return body if isinstance(body, dict) else {"message": str(body)}
        except ValueError:
            return {"message": r.text[:300]}

    def _raise_for(self, r: httpx.Response, what: str) -> None:
        if r.status_code < 400:
            return
        e = self._err(r)
        msg = e.get("message") or r.reason_phrase
        if r.status_code == 401:
            raise ConnectorError(f"MetaApi rejected our API token ({what}): check PS_METAAPI_TOKEN")
        if r.status_code == 404:
            raise RetryableError(f"MetaApi account not found or not ready yet ({what})")
        if r.status_code == 429 or r.status_code >= 500:
            raise RetryableError(f"MetaApi {r.status_code} ({what}): {msg}")
        raise ConnectorError(f"MetaApi {r.status_code} ({what}): {msg}")

    # ------------------------------------------------------------------ connect
    async def connect(self, creds: Credentials) -> None:
        if not self.token:
            await self._status("error", "MetaApi is not configured on the server (PS_METAAPI_TOKEN)")
            raise ConnectorError("PS_METAAPI_TOKEN is not set")
        self.http = httpx.AsyncClient(headers={"auth-token": self.token}, timeout=httpx.Timeout(30.0, connect=10.0),
                                      transport=self._transport)
        self.platform = (creds.get("platform") or "mt5").lower()
        self.account_id = creds.get("account_id")
        if not self.account_id:
            if not self.provision:
                raise RetryableError("MetaTrader account is still being set up")
            await self._status("connecting", "Registering your account with MetaTrader")
            self.account_id = await self._provision(creds)
            if self.on_provisioned is not None:
                await self.on_provisioned(self.account_id, self.platform)
        await self._wait_connected()

    async def _provision(self, creds: Credentials) -> str:
        login = str(creds.get("login", "")).strip()
        if not login.isdigit():
            await self._status("error", "The account number should be digits only")
            raise AuthError("login must be digits")
        body = {"name": f"PipStream {self.broker_account_id}"[:60], "login": login, "password": creds["password"],
                "server": creds["server"], "platform": self.platform, "magic": MAGIC, "type": "cloud-g2",
                "reliability": "regular", "tags": ["pipstream"]}
        if creds.get("region"):
            body["region"] = creds["region"]
        txid = secrets.token_hex(16)  # 32 chars; reused while MetaApi answers 202
        deadline = time.monotonic() + self.connect_timeout_s
        while True:
            r = await self._req("POST", f"{self.prov_url}/users/current/accounts", json=body,
                                headers={"transaction-id": txid})
            if r.status_code == 202:
                if time.monotonic() > deadline:
                    await self._status("error", "MetaTrader server detection took too long, try again later")
                    raise RetryableError("metaapi broker detection still in progress")
                await self._status("connecting", "Finding your broker's server")
                await self._sleep(min(float(r.headers.get("retry-after") or 30), 60.0))
                continue
            if r.status_code in (200, 201):
                break
            e = self._err(r)
            details = e.get("details")
            code = details.get("code") if isinstance(details, dict) else details
            if code == "E_AUTH":
                await self._status("error", "Wrong account number or password for that server")
                raise AuthError("MetaTrader rejected the login or password")
            if code == "E_SRV_NOT_FOUND":
                hint = ""
                if isinstance(details, dict) and details.get("serversByBrokers"):
                    names = [s for v in details["serversByBrokers"].values() for s in (v or [])][:5]
                    hint = f". Did you mean: {', '.join(names)}?" if names else ""
                await self._status("error", f"Server \"{creds['server']}\" not found{hint}")
                raise AuthError(f"server not found{hint}")
            if code == "E_SERVER_TIMEZONE":
                await self._status("error", "Couldn't read your broker's settings yet, try again in a few minutes")
                raise RetryableError("metaapi E_SERVER_TIMEZONE")
            self._raise_for(r, "create account")
        acct = r.json()
        account_id = acct.get("id") or acct.get("_id")
        if not account_id:
            raise ConnectorError("MetaApi did not return an account id")
        if acct.get("state") != "DEPLOYED":
            d = await self._req("POST", f"{self.prov_url}/users/current/accounts/{account_id}/deploy")
            self._raise_for(d, "deploy")
        await self._status("connecting", "Starting your MetaTrader terminal")
        return account_id

    async def read_account(self) -> dict:
        r = await self._req("GET", f"{self.prov_url}/users/current/accounts/{self.account_id}")
        if r.status_code == 404:
            await self._status("error", "This account was removed from MetaApi; please connect it again")
            raise AuthError("metaapi account no longer exists")
        self._raise_for(r, "read account")
        return r.json()

    async def _wait_connected(self) -> None:
        deadline = time.monotonic() + self.connect_timeout_s
        deployed_once = False
        while True:
            a = await self.read_account()
            region = a.get("region") or self.default_region
            self.client_url = self.client_tpl.format(region=region).rstrip("/")
            state, cs = a.get("state"), a.get("connectionStatus")
            if state == "UNDEPLOYED" and not deployed_once:
                d = await self._req("POST", f"{self.prov_url}/users/current/accounts/{self.account_id}/deploy")
                self._raise_for(d, "deploy")
                deployed_once = True
            if state == "DEPLOYED" and cs == "CONNECTED":
                self.connected = True
                await self._status("connected", f"{self.platform.upper()} account connected")
                return
            if cs == "DISCONNECTED_FROM_BROKER":
                await self._status("connecting", "Terminal is up, waiting for your broker's server")
            else:
                await self._status("connecting", "Starting your MetaTrader terminal")
            if time.monotonic() > deadline:
                raise RetryableError("metaapi account still connecting")
            await self._sleep(5.0)

    # ------------------------------------------------------------------ trading
    def _acct(self, path: str) -> str:
        return f"{self.client_url}/users/current/accounts/{self.account_id}{path}"

    async def _trade(self, body: dict, what: str) -> dict:
        r = await self._req("POST", self._acct("/trade"), json=body)
        self._raise_for(r, what)
        res = r.json()
        sc = res.get("stringCode", "")
        if sc in _DONE or res.get("numericCode") in (10008, 10009, 10010):
            return res
        if sc in _RETRY:
            raise RetryableError(f"{sc}: {res.get('message')}")
        raise ConnectorError(f"{sc or res.get('numericCode')}: {res.get('message') or 'rejected'}")

    async def open(self, intent: TradeIntent) -> OrderResult:
        kind = {("buy", "market"): "ORDER_TYPE_BUY", ("sell", "market"): "ORDER_TYPE_SELL",
                ("buy", "limit"): "ORDER_TYPE_BUY_LIMIT", ("sell", "limit"): "ORDER_TYPE_SELL_LIMIT",
                ("buy", "stop"): "ORDER_TYPE_BUY_STOP", ("sell", "stop"): "ORDER_TYPE_SELL_STOP"}
        body: dict[str, Any] = {"actionType": kind[(intent.side, intent.order_type)], "symbol": intent.symbol,
                                "volume": float(intent.volume), "magic": MAGIC,
                                # comment + clientId must total <= 26 characters
                                "comment": "PipStream",
                                "clientId": f"ps{intent.source_message_id}t{intent.tp_index or 0}"[:16]}
        if intent.order_type != "market":
            body["openPrice"] = float(intent.price)
        if intent.sl:
            body["stopLoss"] = float(intent.sl)
        if intent.tp:
            body["takeProfit"] = float(intent.tp)
        res = await self._trade(body, "open")
        ticket = res.get("positionId") or res.get("orderId")
        price = None
        if ticket and res.get("positionId"):
            try:
                p = await self._req("GET", self._acct(f"/positions/{ticket}"))
                if p.status_code == 200:
                    price = p.json().get("openPrice")
            except RetryableError:
                pass
        return OrderResult(ok=True, ticket=str(ticket) if ticket else None, price=price,
                           volume=float(intent.volume), raw={"stringCode": res.get("stringCode")})

    async def modify(self, ticket: str, sl: float | None, tp: float | None) -> OrderResult:
        body: dict[str, Any] = {"actionType": "POSITION_MODIFY", "positionId": str(ticket)}
        if sl is not None:
            body["stopLoss"] = float(sl)
        if tp is not None:
            body["takeProfit"] = float(tp)
        res = await self._trade(body, "modify")
        return OrderResult(ok=True, ticket=str(ticket), raw={"stringCode": res.get("stringCode")})

    async def close(self, ticket: str, volume: float | None = None) -> OrderResult:
        body: dict[str, Any] = {"actionType": "POSITION_CLOSE_ID", "positionId": str(ticket)}
        if volume:
            body = {"actionType": "POSITION_PARTIAL", "positionId": str(ticket), "volume": float(volume)}
        res = await self._trade(body, "close")
        return OrderResult(ok=True, ticket=str(ticket), volume=volume, raw={"stringCode": res.get("stringCode")})

    async def positions(self) -> list[Position]:
        r = await self._req("GET", self._acct("/positions"))
        self._raise_for(r, "positions")
        out = []
        for p in r.json() or []:
            if int(p.get("magic") or 0) != MAGIC:
                continue  # never touch trades the customer opened by hand
            out.append(Position(ticket=str(p["id"]), symbol=p["symbol"],
                                side="buy" if p.get("type") == "POSITION_TYPE_BUY" else "sell",
                                volume=float(p["volume"]), open_price=float(p.get("openPrice") or 0),
                                sl=p.get("stopLoss") or None, tp=p.get("takeProfit") or None,
                                profit=p.get("profit")))
        return out

    async def account_info(self, symbols: list[str] | None = None) -> AccountInfo:
        r = await self._req("GET", self._acct("/account-information"))
        self._raise_for(r, "account information")
        a = r.json()
        specs: dict[str, SymbolSpec] = {}
        for sym in await self._broker_symbols(symbols or []):
            s = await self._req("GET", self._acct(f"/symbols/{sym}/specification"))
            if s.status_code != 200:
                continue  # symbol not offered by this broker (or named differently)
            spec = s.json()
            q = await self._req("GET", self._acct(f"/symbols/{sym}/current-price"))
            price = q.json() if q.status_code == 200 else {}
            tick_size = float(spec.get("tickSize") or 10 ** -int(spec.get("digits", 5)))
            tick_value = price.get("lossTickValue") or price.get("profitTickValue") or \
                tick_size * float(spec.get("contractSize") or 1)
            specs[sym] = SymbolSpec(point=10 ** -int(spec.get("digits", 5)), tick_size=tick_size,
                                    tick_value=float(tick_value), contract_size=float(spec.get("contractSize") or 1),
                                    volume_min=float(spec.get("minVolume") or 0.01),
                                    volume_max=float(spec.get("maxVolume") or 100),
                                    volume_step=float(spec.get("volumeStep") or 0.01))
        return AccountInfo(balance=float(a.get("balance") or 0), equity=float(a.get("equity") or 0),
                           currency=a.get("currency") or "USD", symbols=specs)

    async def _broker_symbols(self, wanted: list[str]) -> list[str]:
        """Map canonical symbols to what this broker calls them (Exness: XAUUSDm, others:
        XAUUSD.r, EURUSD-ECN ...). The broker's list is fetched once per connection."""
        if not wanted:
            return []
        if self._symbols is None:
            r = await self._req("GET", self._acct("/symbols"))
            self._symbols = [x if isinstance(x, str) else x.get("symbol", "") for x in r.json()] \
                if r.status_code == 200 and isinstance(r.json(), list) else []
        if not self._symbols:
            return list(wanted)
        have = set(self._symbols)
        out = []
        for w in wanted:
            if w in have:
                out.append(w)
                continue
            cands = sorted(s for s in self._symbols if s.upper().startswith(w.upper()) and len(s) <= len(w) + 4)
            if cands:
                out.append(cands[0])
        return out

    async def health(self) -> Health:
        t0 = time.monotonic()
        try:
            a = await self.read_account()
        except Exception as e:
            return Health(ok=False, detail=repr(e))
        ok = a.get("state") == "DEPLOYED" and a.get("connectionStatus") == "CONNECTED"
        return Health(ok=ok, detail=f"{a.get('state')} / {a.get('connectionStatus')}",
                      latency_ms=(time.monotonic() - t0) * 1000)

    async def disconnect(self) -> None:
        if self.http is not None:
            await self.http.aclose()
            self.http = None


async def remove_account(settings, account_id: str, transport=None) -> None:
    """Delete a MetaApi account when the customer removes it from PipStream (stops billing)."""
    if not getattr(settings, "metaapi_token", None):
        return
    url = (getattr(settings, "metaapi_provisioning_url", None) or PROVISIONING_URL).rstrip("/")
    async with httpx.AsyncClient(headers={"auth-token": settings.metaapi_token}, timeout=30.0,
                                 transport=transport) as http:
        r = await http.delete(f"{url}/users/current/accounts/{account_id}")
        if r.status_code not in (204, 404):
            raise ConnectorError(f"MetaApi delete failed: {r.status_code}")
