"""Environment-driven settings. Nothing is hardcoded: every value comes from PS_* env vars.

Each service only receives the secrets it needs (see docker-compose.yml / .env.example).
"""
from __future__ import annotations

import os
from functools import lru_cache

from pydantic import BaseModel


class Settings(BaseModel):
    service_name: str = "pipstream"
    env: str = "dev"
    log_level: str = "INFO"

    redis_url: str = "redis://localhost:6379/0"
    database_url: str = "postgresql://pipstream:pipstream@localhost:5432/pipstream"
    stream_prefix: str = "ps"
    stream_maxlen: int = 100_000

    # Trade-intent signing (Ed25519, base64 raw keys). Private key: risk engine only.
    intent_signing_key: str | None = None
    intent_verify_key: str | None = None
    intent_key_id: str = "k1"
    intent_ttl_ms: int = 5000

    # Envelope key(s) for Telegram sessions and broker credentials (comma-separated
    # Fernet keys, newest first, to allow rotation). Only ingestor, router, core-api.
    secrets_master_keys: str | None = None

    # Core API
    admin_token: str | None = None
    api_host: str = "0.0.0.0"
    api_port: int = 8080

    # Ingestor
    telegram_api_id: int | None = None
    telegram_api_hash: str | None = None
    ingestor_source: str = "telethon"  # telethon | replay
    ingestor_replay_file: str | None = None
    ingestor_shard: int = 0
    ingestor_shards: int = 1
    ingestor_lease_ttl_ms: int = 15_000

    # Router
    router_global_max_lot: float = 5.0
    router_snapshot_interval_s: float = 30.0

    # Notifications
    telegram_bot_token: str | None = None
    smtp_url: str | None = None  # smtp://user:pass@host:587
    smtp_from: str | None = None

    # Billing: "provider:secret,provider:secret" (webhook signature secrets)
    billing_webhook_secrets: str | None = None

    # Seed / config
    config_file: str = "config/pipstream.yaml"
    symbols_file: str | None = None

    @classmethod
    def from_env(cls, **overrides) -> "Settings":
        data: dict = {}
        for name in cls.model_fields:
            val = os.environ.get(f"PS_{name.upper()}")
            if val is not None and val != "":
                data[name] = val
        data.update(overrides)
        return cls(**data)


@lru_cache
def get_settings() -> Settings:
    return Settings.from_env()
