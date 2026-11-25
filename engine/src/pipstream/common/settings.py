"""Environment-driven settings. Nothing is hardcoded: every value comes from PS_* env vars.

Each worker only receives the secrets it needs (see the repo-root docker-compose.yml).
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

    # Sealed-box keys (X25519, base64). Laravel holds only the public halves.
    intake_private_key: str | None = None     # opens Telegram sessions (intake only)
    executor_private_key: str | None = None   # opens broker credentials (executor only)
    executor_public_key: str | None = None    # for the dev/test seeder

    # Intake: internal HTTP for parse-preview (private network only, never published)
    intake_http_host: str = "0.0.0.0"
    intake_http_port: int = 8001

    # Ingestor
    telegram_api_id: int | None = None
    telegram_api_hash: str | None = None
    ingestor_source: str = "telethon"  # telethon | replay
    ingestor_replay_file: str | None = None
    ingestor_shard: int = 0
    ingestor_shards: int = 1
    ingestor_lease_ttl_ms: int = 15_000
    ingestor_confirm_to_saved: bool = False  # post "PipStream read ..." to the reader's Saved Messages
    ingestor_fake_telegram: bool = False  # dev only: web logins accept code 12345 (11111 -> 2FA "demo")

    # Risk: PS_RISK_CHECKS=false skips the business checks (plan/subscription, live allowed,
    # channel filters, entry slippage, require SL, max open trades, daily loss) so every
    # parsed signal becomes a trade. Kill switches and position sizing still apply. Beta/testing.
    risk_checks: bool = True
    # Duplicate-signal guard: the same channel + symbol + side + levels again within this many
    # seconds opens nothing for a rule that already copied it (0 turns it off). A rule can set
    # its own with limits.duplicate_window_min.
    risk_duplicate_window_s: int = 1800

    # Router
    router_global_max_lot: float = 5.0
    router_snapshot_interval_s: float = 30.0
    # Modify / close orders that failed for a passing reason (terminal offline, timeout, network)
    # are tried again after these delays in seconds. Opens are never retried after their 5 s
    # window: a late or doubled entry is worse than a missed one.
    router_followup_retry_s: str = "5,15,45,120"

    # MetaApi (hosted MT4/MT5 terminals, metaapi.cloud). Token: executor only.
    metaapi_token: str | None = None
    metaapi_provisioning_url: str | None = None   # default: MetaApi's public provisioning API
    metaapi_client_url: str | None = None         # default: https://mt-client-api-v1.{region}.agiliumtrade.ai
    metaapi_region: str | None = None             # fallback when the account reports none
    metaapi_connect_timeout_s: float = 240.0      # how long set-up waits for the terminal to connect

    # Schema (owned by Laravel; Python reads the same SQL file for tests and dev)
    schema_file: str | None = None

    # Dev/test seed
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
