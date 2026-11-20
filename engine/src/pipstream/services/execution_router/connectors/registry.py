"""Connector registry: code plugins register by name; the `connectors` table enables them."""
from __future__ import annotations

from collections.abc import Callable
from typing import Any

from .base import Connector

_REGISTRY: dict[str, Callable[..., Connector]] = {}


def register(name: str):
    def deco(cls):
        cls.name = name
        _REGISTRY[name] = cls
        return cls
    return deco


def create(name: str, **deps: Any) -> Connector:
    _load_builtin()
    if name not in _REGISTRY:
        raise KeyError(f"unknown connector {name!r}; known: {sorted(_REGISTRY)}")
    return _REGISTRY[name](**deps)


def available() -> list[str]:
    _load_builtin()
    return sorted(_REGISTRY)


def _load_builtin() -> None:
    from . import deriv_options, ea_bridge, metaapi, mt5_bridge, paper  # noqa: F401  (import registers)
