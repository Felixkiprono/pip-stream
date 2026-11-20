"""Live Telegram source: one Telethon client per linked user account (userbot, read-only).

Rules: never post in channels, never bulk-join, never touch read status; FloodWait is handled
by Telethon (flood_sleep_threshold). Session strings arrive decrypted in memory only. The one
write is opt-in (PS_INGESTOR_CONFIRM_TO_SAVED): a note to the account's own Saved Messages.

Posts are read whether they arrive from others or were sent by this account itself (a channel
you own and post in), since both are signals to copy. Private chats are always ignored.
"""
from __future__ import annotations

import logging

from .base import OnPost, RawPost

log = logging.getLogger("pipstream.ingestor.telethon")


class TelethonSource:
    def __init__(self, account_id: str, session_string: str, api_id: int, api_hash: str):
        self.account_id = account_id
        self._session = session_string
        self._api_id, self._api_hash = api_id, api_hash
        self._client = None

    async def start(self, on_post: OnPost) -> None:
        from telethon import TelegramClient, events
        from telethon.sessions import StringSession

        from ..login_flow import DEVICE
        client = TelegramClient(StringSession(self._session), self._api_id, self._api_hash,
                                flood_sleep_threshold=60, receive_updates=True, **DEVICE)
        self._session = None  # drop plaintext reference
        await client.connect()
        if not await client.is_user_authorized():
            raise RuntimeError(f"telegram session for {self.account_id} is not authorized")
        # A fresh StringSession has no entity cache; without it Telegram's channel updates can't
        # be resolved and posts are silently missed. One dialogs fetch fills it.
        await client.get_dialogs()

        async def _emit(event, is_edit: bool):
            m = event.message
            if not event.is_channel and not event.is_group:
                return
            reply_to = m.reply_to.reply_to_msg_id if m.reply_to else None
            await on_post(self.account_id, RawPost(
                tg_chat_id=event.chat_id, message_id=m.id, text=m.message or "",
                reply_to_id=reply_to, is_edit=is_edit, has_media=m.media is not None,
                posted_at=m.date))

        client.add_event_handler(lambda e: _emit(e, False), events.NewMessage())
        client.add_event_handler(lambda e: _emit(e, True), events.MessageEdited())
        self._client = client
        log.info("telethon source connected", extra={"ctx": {"telegram_account_id": self.account_id}})

    async def whoami(self) -> dict:
        u = await self._client.get_me()
        name = " ".join(x for x in (u.first_name, u.last_name) if x)
        return {"name": name, "username": u.username}

    async def send_to_saved(self, text: str) -> None:
        """Write a note to this account's own Saved Messages (never to a channel)."""
        if self._client:
            await self._client.send_message("me", text)

    async def stop(self) -> None:
        if self._client:
            await self._client.disconnect()
            self._client = None
