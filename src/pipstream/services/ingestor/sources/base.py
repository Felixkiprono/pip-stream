from __future__ import annotations

from collections.abc import Awaitable, Callable
from dataclasses import dataclass
from datetime import datetime
from typing import Protocol


@dataclass
class RawPost:
    tg_chat_id: int
    message_id: int
    text: str
    reply_to_id: int | None
    is_edit: bool
    has_media: bool
    posted_at: datetime


OnPost = Callable[[str, RawPost], Awaitable[None]]  # (telegram_account_id, post)


class Source(Protocol):
    account_id: str

    async def start(self, on_post: OnPost) -> None: ...
    async def stop(self) -> None: ...
