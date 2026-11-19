"""Paystack webhooks: HMAC-SHA512 of the raw body with the secret key, header
x-paystack-signature. We expect metadata.user_id and metadata.plan_id on the charge."""
import hashlib
import hmac
import json

from .base import InvalidWebhook, PaymentEvent, register


@register("paystack")
class PaystackProvider:
    def __init__(self, secret: str):
        self.secret = secret.encode()

    def verify_and_parse(self, headers, body):
        sig = headers.get("x-paystack-signature", "")
        good = hmac.new(self.secret, body.encode(), hashlib.sha512).hexdigest()
        if not hmac.compare_digest(sig, good):
            raise InvalidWebhook("bad signature")
        d = json.loads(body)
        if d.get("event") != "charge.success":
            return None
        data = d["data"]
        meta = data.get("metadata") or {}
        return PaymentEvent(user_id=meta["user_id"], plan_id=meta["plan_id"], status="active",
                            amount_cents=int(data["amount"]), currency=data.get("currency", "KES"),
                            provider_ref=str(data["reference"]))
