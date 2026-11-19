"""Encryption for Telegram session strings and broker credentials.

MVP: server-held Fernet keys (AES-128-CBC + HMAC) from PS_SECRETS_MASTER_KEYS, newest first,
so keys can be rotated. Production: replace `SecretBox` with a KMS/Vault-backed envelope
implementation with the same encrypt/decrypt interface.

Plaintext is decrypted only in memory, only in the service that needs it, and never logged.
"""
from __future__ import annotations

import json
from typing import Any

from cryptography.fernet import Fernet, MultiFernet


class SecretBox:
    def __init__(self, keys: str):
        ks = [k.strip() for k in keys.split(",") if k.strip()]
        if not ks:
            raise ValueError("PS_SECRETS_MASTER_KEYS is empty")
        self._f = MultiFernet([Fernet(k) for k in ks])

    def encrypt(self, value: Any) -> str:
        return "f1:" + self._f.encrypt(json.dumps(value).encode()).decode()

    def decrypt(self, token: str) -> Any:
        if not token.startswith("f1:"):
            raise ValueError("unknown ciphertext format")
        return json.loads(self._f.decrypt(token[3:].encode()))

    def rotate(self, token: str) -> str:
        return "f1:" + self._f.rotate(token[3:].encode()).decode()

    @staticmethod
    def generate_key() -> str:
        return Fernet.generate_key().decode()
