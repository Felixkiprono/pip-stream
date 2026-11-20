"""Shared event contracts. Every message on the bus is an `Event` envelope whose `payload`
validates against the model registered for its topic in `TOPIC_PAYLOADS`.

Changing a payload model is a contract change: add fields as optional, never repurpose them.
"""
from __future__ import annotations

from datetime import datetime, timezone
from enum import StrEnum
from typing import Literal

from pydantic import BaseModel, Field

from .ids import new_id


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


class Topic(StrEnum):
    TG_MESSAGE_NEW = "tg.message.new"
    TG_MESSAGE_EDITED = "tg.message.edited"
    SIGNAL_PARSED = "signal.parsed"
    SIGNAL_REJECTED = "signal.rejected"
    TRADE_INTENT = "trade.intent"
    TRADE_EXECUTED = "trade.executed"
    TRADE_FAILED = "trade.failed"
    TRADE_CLOSED = "trade.closed"
    RISK_LIMIT_HIT = "risk.limit_hit"
    ACCOUNT_SNAPSHOT = "account.snapshot"
    BILLING_CHANGED = "billing.changed"
    CONFIG_CHANGED = "config.changed"
    TG_LOGIN_REQUEST = "tg.login.request"
    TG_LOGIN_STATUS = "tg.login.status"


# ---------------------------------------------------------------- Telegram

class TgMessage(BaseModel):
    telegram_account_id: str
    channel_id: str  # internal id (channels.id)
    tg_chat_id: int
    message_id: int
    reply_to_id: int | None = None
    edit_version: int = 0
    text: str
    has_media: bool = False
    posted_at: datetime = Field(default_factory=utcnow)


# ---------------------------------------------------------------- Signals

class SignalSource(BaseModel):
    message_id: int
    reply_to_id: int | None = None
    edit_version: int = 0
    posted_at: datetime | None = None  # for post -> order latency


class EntrySpec(BaseModel):
    type: Literal["market", "limit", "stop", "range"] = "market"
    price: float | None = None  # limit/stop/market-hint
    from_: float | None = Field(default=None, alias="from")
    to: float | None = None

    model_config = {"populate_by_name": True}

    def reference_price(self) -> float | None:
        if self.type == "range" and self.from_ is not None and self.to is not None:
            return (self.from_ + self.to) / 2
        return self.price


class OptionsSpec(BaseModel):
    """Deriv-style options signal: direction + duration (+ stake), no SL/TP."""
    contract: Literal["rise", "fall"]
    duration: int
    duration_unit: Literal["t", "s", "m", "h", "d"]
    stake: float | None = None


class ParsedSignal(BaseModel):
    signal_id: str = Field(default_factory=lambda: new_id("sig"))
    channel_id: str
    source: SignalSource
    kind: Literal["open", "modify", "close", "options"]
    symbol: str | None = None  # canonical symbol (XAUUSD, EURUSD, R_75 ...)
    side: Literal["buy", "sell"] | None = None
    entry: EntrySpec | None = None
    sl: float | None = None
    tps: list[float] = Field(default_factory=list)
    # distances in pips when the post gave "SL 30 pips" and no entry price to anchor to;
    # resolved to prices by the risk engine using the live/reference price
    sl_pips: float | None = None
    tp_pips: list[float] = Field(default_factory=list)
    # follow-ups (kind=modify|close)
    move_sl_to_entry: bool = False
    new_sl: float | None = None
    new_tp: float | None = None
    close_fraction: float | None = None  # 1.0 = close all
    options: OptionsSpec | None = None
    confidence: float
    parser_profile: str | None = None
    template: str | None = None
    raw_text: str


class SignalRejected(BaseModel):
    channel_id: str
    source: SignalSource
    reason: str
    confidence: float = 0.0
    raw_text: str


# ---------------------------------------------------------------- Trading

class TradeIntent(BaseModel):
    """Signed instruction from the Rules/Risk Engine to the Execution Router.

    The router refuses any intent whose signature does not verify, which has expired
    (`expires_at`, 5 s by default) or whose idempotency key was already claimed.
    """
    intent_id: str = Field(default_factory=lambda: new_id("int"))
    idempotency_key: str
    user_id: str
    broker_account_id: str
    copy_rule_id: str
    connector: str
    mode: Literal["paper", "live"]
    action: Literal["open", "modify", "close"]
    signal_id: str
    channel_id: str
    source_message_id: int
    symbol: str  # broker symbol (after symbol_map)
    side: Literal["buy", "sell"] | None = None
    order_type: Literal["market", "limit", "stop"] = "market"
    price: float | None = None
    ref_price: float | None = None
    volume: float | None = None  # lots, or stake for options
    sl: float | None = None
    tp: float | None = None
    tp_index: int | None = None
    ticket: str | None = None  # modify/close target
    options: OptionsSpec | None = None
    max_lot: float | None = None
    posted_at: datetime | None = None
    created_at: datetime = Field(default_factory=utcnow)
    expires_at: datetime
    key_id: str = "k1"
    signature: str | None = None


class TradeResult(BaseModel):
    intent_id: str
    user_id: str
    broker_account_id: str
    connector: str
    mode: Literal["paper", "live"]
    action: Literal["open", "modify", "close"]
    status: Literal["executed", "failed"]
    signal_id: str
    channel_id: str
    source_message_id: int
    symbol: str
    side: Literal["buy", "sell"] | None = None
    ticket: str | None = None
    volume: float | None = None
    price: float | None = None
    sl: float | None = None
    tp: float | None = None
    tp_index: int | None = None
    closed_volume: float | None = None
    profit: float | None = None
    error: str | None = None
    latency_ms: int | None = None  # telegram post -> broker ack


class TradeClosed(BaseModel):
    user_id: str
    broker_account_id: str
    ticket: str
    symbol: str
    close_price: float | None = None
    profit: float | None = None
    reason: str = "broker"  # broker (SL/TP/manual) | intent


class RiskLimitHit(BaseModel):
    user_id: str
    broker_account_id: str
    copy_rule_id: str
    signal_id: str
    limit: str
    detail: str


class SymbolSpec(BaseModel):
    point: float
    tick_size: float
    tick_value: float  # account currency per tick per 1.0 lot
    contract_size: float = 1.0
    volume_min: float = 0.01
    volume_max: float = 100.0
    volume_step: float = 0.01


class AccountInfo(BaseModel):
    balance: float
    equity: float
    currency: str = "USD"
    symbols: dict[str, SymbolSpec] = Field(default_factory=dict)


class Position(BaseModel):
    ticket: str
    symbol: str
    side: Literal["buy", "sell"]
    volume: float
    open_price: float
    sl: float | None = None
    tp: float | None = None
    profit: float | None = None


class AccountSnapshot(BaseModel):
    broker_account_id: str
    user_id: str
    info: AccountInfo
    positions: list[Position]


# ---------------------------------------------------------------- Control plane

class ConfigChanged(BaseModel):
    entity: str  # channel | parser_profile | broker_account | copy_rule | kill_switch
    entity_id: str | None = None


class BillingChanged(BaseModel):
    user_id: str
    plan_id: str
    status: str


# ---------------------------------------------------------------- Telegram account linking
# The web app (Laravel) drives the login; intake talks to Telegram. The phone number and
# login code pass through Redis once and are never stored.

class TgLoginRequest(BaseModel):
    request_id: str
    telegram_account_id: str
    user_id: str
    step: Literal["send_code", "sign_in", "password", "list_channels", "logout", "test", "resend", "qr"]
    phone: str | None = None
    code: str | None = None
    password: str | None = None


class TgLoginStatus(BaseModel):
    request_id: str
    telegram_account_id: str
    user_id: str
    status: Literal["code_sent", "password_needed", "linked", "channels", "logged_out", "test_ok", "qr", "error"]
    phone_hint: str | None = None
    channels: list[dict] = Field(default_factory=list)  # [{tg_chat_id, title, username}]
    info: dict | None = None  # code_sent: delivery; qr: {qr, url, expires}; test_ok: {name, username, live}
    error: str | None = None


TOPIC_PAYLOADS: dict[Topic, type[BaseModel]] = {
    Topic.TG_MESSAGE_NEW: TgMessage,
    Topic.TG_MESSAGE_EDITED: TgMessage,
    Topic.SIGNAL_PARSED: ParsedSignal,
    Topic.SIGNAL_REJECTED: SignalRejected,
    Topic.TRADE_INTENT: TradeIntent,
    Topic.TRADE_EXECUTED: TradeResult,
    Topic.TRADE_FAILED: TradeResult,
    Topic.TRADE_CLOSED: TradeClosed,
    Topic.RISK_LIMIT_HIT: RiskLimitHit,
    Topic.ACCOUNT_SNAPSHOT: AccountSnapshot,
    Topic.BILLING_CHANGED: BillingChanged,
    Topic.CONFIG_CHANGED: ConfigChanged,
    Topic.TG_LOGIN_REQUEST: TgLoginRequest,
    Topic.TG_LOGIN_STATUS: TgLoginStatus,
}


class Event(BaseModel):
    event_id: str = Field(default_factory=lambda: new_id("evt"))
    topic: Topic
    trace_id: str
    occurred_at: datetime = Field(default_factory=utcnow)
    producer: str
    payload: dict

    def parse(self) -> BaseModel:
        return TOPIC_PAYLOADS[self.topic].model_validate(self.payload)
