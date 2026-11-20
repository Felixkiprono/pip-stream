"""Replay source for development, demos and tests: plays a JSONL file of posts as if they
arrived from Telegram. Lines: {"tg_chat_id": -100.., "message_id": 1, "text": "...",
"reply_to_id": null, "edit": false, "delay_ms": 500}"""
from __future__ import annotations

import asyncio
import json
from datetime import datetime, timezone
from pathlib import Path

from .base import OnPost, RawPost


class ReplaySource:
    def __init__(self, account_id: str, path: str, loop_forever: bool = False):
        self.account_id = account_id
        self.path = Path(path)
        self.loop_forever = loop_forever
        self._task: asyncio.Task | None = None

    async def start(self, on_post: OnPost) -> None:
        async def play():
            while True:
                for line in self.path.read_text().splitlines():
                    line = line.strip()
                    if not line or line.startswith("//"):
                        continue
                    d = json.loads(line)
                    await asyncio.sleep(d.get("delay_ms", 300) / 1000)
                    await on_post(self.account_id, RawPost(
                        tg_chat_id=int(d["tg_chat_id"]), message_id=int(d["message_id"]), text=d["text"],
                        reply_to_id=d.get("reply_to_id"), is_edit=bool(d.get("edit")),
                        has_media=bool(d.get("has_media")), posted_at=datetime.now(timezone.utc)))
                if not self.loop_forever:
                    return
        self._task = asyncio.create_task(play())

    async def stop(self) -> None:
        if self._task:
            self._task.cancel()

    async def wait(self) -> None:
        if self._task:
            await self._task
