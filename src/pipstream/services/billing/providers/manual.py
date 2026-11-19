"""Manual/admin provider for the experiment: body is JSON signed with HMAC-SHA256 in
header x-pipstream-signature."""
import hashlib
import hmac
import json

from .base import InvalidWebhook, PaymentEvent, register


@register("manual")
class ManualProvider:
    def __init__(self, secret: str):
        self.secret = secret.encode()

    def verify_and_parse(self, headers, body):
        sig = headers.get("x-pipstream-signature", "")
        good = hmac.new(self.secret, body.encode(), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(sig, good):
            raise InvalidWebhook("bad signature")
        d = json.loads(body)
        return PaymentEvent(user_id=d["user_id"], plan_id=d["plan_id"], status=d.get("status", "active"),
                            amount_cents=int(d.get("amount_cents", 0)), currency=d.get("currency", "KES"),
                            provider_ref=d["ref"])
