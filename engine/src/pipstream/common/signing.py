"""Ed25519 signing of trade intents. Only the risk engine holds the private key; the
execution router holds the public key. An intent is accepted only if it verifies, has not
expired and its idempotency key has never been claimed (see execution_router)."""
from __future__ import annotations

import base64
import json
import sys
from datetime import datetime, timedelta, timezone

from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey, Ed25519PublicKey
from cryptography.hazmat.primitives.serialization import Encoding, NoEncryption, PrivateFormat, PublicFormat

from .events import TradeIntent


def canonical_bytes(intent: TradeIntent) -> bytes:
    data = intent.model_dump(mode="json", exclude={"signature"})
    return json.dumps(data, sort_keys=True, separators=(",", ":")).encode()


class IntentSigner:
    def __init__(self, private_key_b64: str, key_id: str = "k1", ttl_ms: int = 5000):
        self._key = Ed25519PrivateKey.from_private_bytes(base64.b64decode(private_key_b64))
        self.key_id = key_id
        self.ttl = timedelta(milliseconds=ttl_ms)

    def expiry(self, now: datetime | None = None) -> datetime:
        return (now or datetime.now(timezone.utc)) + self.ttl

    def sign(self, intent: TradeIntent) -> TradeIntent:
        intent = intent.model_copy(update={"key_id": self.key_id, "signature": None})
        sig = self._key.sign(canonical_bytes(intent))
        return intent.model_copy(update={"signature": base64.b64encode(sig).decode()})


class IntentVerifier:
    def __init__(self, public_keys_b64: dict[str, str]):
        self._keys = {kid: Ed25519PublicKey.from_public_bytes(base64.b64decode(k))
                      for kid, k in public_keys_b64.items()}

    @classmethod
    def from_setting(cls, value: str, default_kid: str = "k1") -> "IntentVerifier":
        """`value` is "k1:BASE64,k2:BASE64" (rotation) or a bare BASE64 key."""
        keys = {}
        for part in value.split(","):
            part = part.strip()
            if ":" in part:
                kid, k = part.split(":", 1)
            else:
                kid, k = default_kid, part
            keys[kid] = k
        return cls(keys)

    def verify(self, intent: TradeIntent) -> bool:
        key = self._keys.get(intent.key_id)
        if key is None or not intent.signature:
            return False
        try:
            key.verify(base64.b64decode(intent.signature), canonical_bytes(intent))
            return True
        except (InvalidSignature, ValueError):
            return False

    @staticmethod
    def expired(intent: TradeIntent, now: datetime | None = None) -> bool:
        return (now or datetime.now(timezone.utc)) >= intent.expires_at


def generate_keypair() -> tuple[str, str]:
    k = Ed25519PrivateKey.generate()
    priv = k.private_bytes(Encoding.Raw, PrivateFormat.Raw, NoEncryption())
    pub = k.public_key().public_bytes(Encoding.Raw, PublicFormat.Raw)
    return base64.b64encode(priv).decode(), base64.b64encode(pub).decode()


if __name__ == "__main__":
    priv, pub = generate_keypair()
    sys.stdout.write(f"PS_INTENT_SIGNING_KEY={priv}\nPS_INTENT_VERIFY_KEY={pub}\n")
