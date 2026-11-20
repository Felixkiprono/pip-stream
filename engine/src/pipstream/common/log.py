"""JSON logging with secret redaction. Logs never contain credentials or session strings."""
from __future__ import annotations

import json
import logging
import re
import sys
from datetime import datetime, timezone

_REDACT_KEYS = re.compile(r"(password|token|secret|session|api_hash|ciphertext|signature)", re.I)


def redact(obj):
    if isinstance(obj, dict):
        return {k: ("***" if _REDACT_KEYS.search(str(k)) else redact(v)) for k, v in obj.items()}
    if isinstance(obj, list):
        return [redact(v) for v in obj]
    return obj


class JsonFormatter(logging.Formatter):
    def __init__(self, service: str):
        super().__init__()
        self.service = service

    def format(self, record: logging.LogRecord) -> str:
        out = {
            "ts": datetime.now(timezone.utc).isoformat(timespec="milliseconds"),
            "level": record.levelname,
            "service": self.service,
            "msg": record.getMessage(),
        }
        extra = getattr(record, "ctx", None)
        if extra:
            out.update(redact(extra))
        if record.exc_info:
            out["exc"] = self.formatException(record.exc_info)
        return json.dumps(out, default=str)


def setup_logging(service: str, level: str = "INFO") -> logging.Logger:
    root = logging.getLogger()
    for h in list(root.handlers):
        root.removeHandler(h)
    h = logging.StreamHandler(sys.stdout)
    h.setFormatter(JsonFormatter(service))
    root.addHandler(h)
    root.setLevel(level.upper())
    logging.getLogger("telethon").setLevel("WARNING")
    return logging.getLogger(service)


def ctx(**kw) -> dict:
    """Usage: log.info("msg", extra=ctx(trace_id=..., signal_id=...))"""
    return {"ctx": kw}
