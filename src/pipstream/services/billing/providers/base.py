"""Payment provider plugins. Each verifies its own webhook signature and maps the payload to
a PaymentEvent. Planned: M-Pesa (Daraja STK Push), Paystack, Stripe, Flutterwave."""
from __future__ import annotations

from dataclasses import dataclass
from typing import Protocol

_REGISTRY: dict[str, type] = {}


@dataclass
class PaymentEvent:
    user_id: str
    plan_id: str
    status: str  # active | past_due | canceled
    amount_cents: int
    currency: str
    provider_ref: str


class InvalidWebhook(Exception):
    pass


class PaymentProvider(Protocol):
    name: str

    def verify_and_parse(self, headers: dict[str, str], body: str) -> PaymentEvent | None: ...


def register(name: str):
    def deco(cls):
        cls.name = name
        _REGISTRY[name] = cls
        return cls
    return deco


def get(name: str, secret: str):
    from . import manual, paystack  # noqa: F401
    cls = _REGISTRY.get(name)
    if cls is None:
        raise InvalidWebhook(f"unknown provider {name}")
    return cls(secret)
