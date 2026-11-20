"""Secrets that the web app can write but never read.

Broker credentials and Telegram sessions are encrypted with a libsodium *sealed box* to the
public key of the one worker that needs them:

- broker credentials  -> executor's key   (Laravel seals them when a customer saves an account)
- Telegram sessions   -> intake's key     (intake seals them itself after a login)

Laravel only holds the public keys (`sodium_crypto_box_seal` in PHP), so a bug or a leaked
APP_KEY in the web app cannot reveal a broker password. Only the worker holding the private
key can open the box, in memory, and plaintext is never logged.

Ciphertext format: "sb1:<base64 sealed box>" where the plaintext is JSON.
Key format: base64 of the raw 32-byte X25519 key (same bytes PHP's sodium uses).
"""
from __future__ import annotations

import base64
import json
from typing import Any

from nacl.public import PrivateKey, PublicKey, SealedBox

PREFIX = "sb1:"


def _b64d(v: str) -> bytes:
    return base64.b64decode(v.strip())


def seal(value: Any, public_key_b64: str) -> str:
    box = SealedBox(PublicKey(_b64d(public_key_b64)))
    return PREFIX + base64.b64encode(box.encrypt(json.dumps(value).encode())).decode()


class Opener:
    """Holds one worker's private key and opens boxes sealed to it."""

    def __init__(self, private_key_b64: str):
        self._sk = PrivateKey(_b64d(private_key_b64))
        self._box = SealedBox(self._sk)

    @property
    def public_key(self) -> str:
        return base64.b64encode(bytes(self._sk.public_key)).decode()

    def open(self, token: str) -> Any:
        if not token.startswith(PREFIX):
            raise ValueError("unknown ciphertext format")
        return json.loads(self._box.decrypt(base64.b64decode(token[len(PREFIX):])))

    def seal(self, value: Any) -> str:
        return seal(value, self.public_key)


def generate_keypair() -> tuple[str, str]:
    """(private, public), base64."""
    sk = PrivateKey.generate()
    return base64.b64encode(bytes(sk)).decode(), base64.b64encode(bytes(sk.public_key)).decode()
