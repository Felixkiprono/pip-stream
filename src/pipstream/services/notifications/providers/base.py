from __future__ import annotations

from typing import Protocol

_REGISTRY: dict[str, type] = {}


class Provider(Protocol):
    name: str

    async def send(self, address: str | None, subject: str, body: str) -> None: ...


def register(name: str):
    def deco(cls):
        cls.name = name
        _REGISTRY[name] = cls
        return cls
    return deco


def get(name: str):
    from . import email, log, telegram_bot  # noqa: F401
    return _REGISTRY.get(name)
