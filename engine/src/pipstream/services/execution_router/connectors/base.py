"""Connector plugin interface. Every venue (MT5 bridge, hosted MT API, Deriv, cTrader,
exchanges, webhooks, paper) implements this Protocol and registers itself by name.
Only the execution router imports connectors; nothing else ever sees credentials."""
from __future__ import annotations

from typing import Any, Protocol, runtime_checkable

from pydantic import BaseModel

from ....common.events import AccountInfo, Position, TradeIntent

Credentials = dict[str, Any]


class OrderResult(BaseModel):
    ok: bool
    ticket: str | None = None
    price: float | None = None
    volume: float | None = None
    profit: float | None = None
    error: str | None = None
    raw: dict | None = None


class Health(BaseModel):
    ok: bool
    detail: str = ""
    latency_ms: float | None = None


class ConnectorError(Exception):
    """Permanent failure (bad request, rejected by broker) -> do not retry."""


class RetryableError(ConnectorError):
    """Network / timeout / requote -> router retries while the intent is still valid."""


class NotSentError(ConnectorError):
    """The order never reached the venue (terminal offline) -> a modify/close may be sent again later."""


class NoAnswerError(ConnectorError):
    """Sent, but no answer came back: the outcome is unknown, so only idempotent orders are re-sent."""


class AuthError(ConnectorError):
    """Credentials rejected -> disable account, notify user."""


@runtime_checkable
class Connector(Protocol):
    name: str

    async def connect(self, creds: Credentials) -> None: ...
    async def open(self, intent: TradeIntent) -> OrderResult: ...
    async def modify(self, ticket: str, sl: float | None, tp: float | None) -> OrderResult: ...
    async def close(self, ticket: str, volume: float | None = None) -> OrderResult: ...
    async def positions(self) -> list[Position]: ...
    async def account_info(self, symbols: list[str] | None = None) -> AccountInfo: ...
    async def health(self) -> Health: ...
    async def disconnect(self) -> None: ...
