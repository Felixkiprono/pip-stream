"""Linking a Telegram reader account from the web app.

Laravel (React UI) -> `tg.login.request` -> intake talks to Telegram -> `tg.login.status`.

    send_code   {phone}      -> code_sent (Telegram texts/app-messages the code)
    sign_in     {code}       -> linked | password_needed (2FA)
    password    {password}   -> linked
    list_channels            -> channels [{tg_chat_id, title, username}]  (to pick channels)
    logout                   -> logged_out (session revoked at Telegram and wiped here)

On `linked` the session string is sealed to intake's own public key and stored in
telegram_accounts; the ingestor then starts reading with it. The phone number, code and 2FA
password only pass through memory; they are never stored or logged.

A half-finished login lives in this process's memory for LOGIN_TTL_S, so run a single intake
replica for logins (the consumer group delivers every request to one replica).
"""
from __future__ import annotations

import asyncio
import time
from collections.abc import Awaitable, Callable
from dataclasses import dataclass, field

from ...common.events import Event, TgLoginRequest, TgLoginStatus, Topic
from ...common.log import ctx
from ...common.service import Context
from ...common.vault import Opener

LOGIN_TTL_S = 300


def telethon_client_factory(api_id: int, api_hash: str, session: str | None = None):
    from telethon import TelegramClient
    from telethon.sessions import StringSession
    return TelegramClient(StringSession(session or ""), api_id, api_hash, receive_updates=False)


@dataclass
class _Pending:
    client: object
    phone: str
    phone_code_hash: str | None = None
    started: float = field(default_factory=time.monotonic)


class LoginFlow:
    def __init__(self, c: Context, *, client_factory: Callable | None = None,
                 on_linked: Callable[[str], Awaitable[None]] | None = None):
        self.c = c
        s = c.settings
        self.opener = Opener(s.intake_private_key) if s.intake_private_key else None
        self.factory = client_factory or (lambda session=None: telethon_client_factory(
            s.telegram_api_id, s.telegram_api_hash, session))
        self.on_linked = on_linked
        self.pending: dict[str, _Pending] = {}

    async def _reply(self, req: TgLoginRequest, status: str, trace_id: str, **kw) -> None:
        await self.c.bus.publish(Topic.TG_LOGIN_STATUS, TgLoginStatus(
            request_id=req.request_id, telegram_account_id=req.telegram_account_id, user_id=req.user_id,
            status=status, **kw), trace_id=trace_id)

    async def _owned(self, req: TgLoginRequest) -> dict | None:
        return await self.c.db.fetchrow("select id, user_id, session_ciphertext from telegram_accounts "
                                        "where id = $1 and user_id = $2", req.telegram_account_id, req.user_id)

    async def _expire(self) -> None:
        now = time.monotonic()
        for acc, p in list(self.pending.items()):
            if now - p.started > LOGIN_TTL_S:
                self.pending.pop(acc, None)
                await _disconnect(p.client)

    async def handle(self, ev: Event) -> None:
        req: TgLoginRequest = ev.parse()
        await self._expire()
        if self.opener is None:
            await self._reply(req, "error", ev.trace_id, error="intake has no PS_INTAKE_PRIVATE_KEY")
            return
        row = await self._owned(req)
        if row is None:  # never act on an account the requesting user doesn't own
            await self._reply(req, "error", ev.trace_id, error="unknown telegram account")
            return
        try:
            await getattr(self, f"_step_{req.step}")(req, row, ev.trace_id)
        except Exception as exc:  # report a short reason to the web app, never the secrets
            name = type(exc).__name__
            self.c.log.warning("telegram login step failed",
                               extra=ctx(step=req.step, account=req.telegram_account_id, error=name))
            await self.c.db.execute("update telegram_accounts set last_error=$2, updated_at=now() where id=$1",
                                    req.telegram_account_id, name)
            await self._reply(req, "error", ev.trace_id, error=_friendly(name))

    async def _step_send_code(self, req: TgLoginRequest, row, trace_id: str) -> None:
        if not req.phone:
            raise ValueError("phone required")
        old = self.pending.pop(req.telegram_account_id, None)
        if old:
            await _disconnect(old.client)
        client = self.factory()
        await client.connect()
        sent = await client.send_code_request(req.phone)
        self.pending[req.telegram_account_id] = _Pending(client, req.phone, getattr(sent, "phone_code_hash", None))
        await self._reply(req, "code_sent", trace_id, phone_hint=req.phone[-3:])

    async def _step_sign_in(self, req: TgLoginRequest, row, trace_id: str) -> None:
        p = self._pending(req)
        try:
            await p.client.sign_in(phone=p.phone, code=req.code, phone_code_hash=p.phone_code_hash)
        except Exception as exc:
            if type(exc).__name__ == "SessionPasswordNeededError":
                await self._reply(req, "password_needed", trace_id)
                return
            raise
        await self._finish(req, p, trace_id)

    async def _step_password(self, req: TgLoginRequest, row, trace_id: str) -> None:
        p = self._pending(req)
        await p.client.sign_in(password=req.password)
        await self._finish(req, p, trace_id)

    async def _finish(self, req: TgLoginRequest, p: _Pending, trace_id: str) -> None:
        session = p.client.session.save()
        self.pending.pop(req.telegram_account_id, None)
        await _disconnect(p.client)
        await self.c.db.execute(
            "update telegram_accounts set session_ciphertext=$2, phone_hint=$3, status='active', last_error=null, "
            "updated_at=now() where id=$1", req.telegram_account_id, self.opener.seal(session), p.phone[-3:])
        await self.c.db.execute("insert into audit_log (actor, action, entity, entity_id) values "
                                "('intake', 'telegram_linked', 'telegram_account', $1)", req.telegram_account_id)
        del session
        await self._reply(req, "linked", trace_id, phone_hint=p.phone[-3:])
        if self.on_linked:
            await self.on_linked(req.telegram_account_id)

    async def _with_session(self, row):
        if not row["session_ciphertext"]:
            raise ValueError("account not linked")
        client = self.factory(self.opener.open(row["session_ciphertext"]))
        await client.connect()
        return client

    async def _step_list_channels(self, req: TgLoginRequest, row, trace_id: str) -> None:
        client = await self._with_session(row)
        try:
            chans = []
            async for d in client.iter_dialogs():
                if getattr(d, "is_channel", False):
                    ent = getattr(d, "entity", None)
                    chans.append({"tg_chat_id": d.id, "title": d.name, "username": getattr(ent, "username", None)})
        finally:
            await _disconnect(client)
        await self._reply(req, "channels", trace_id, channels=chans)

    async def _step_logout(self, req: TgLoginRequest, row, trace_id: str) -> None:
        if row["session_ciphertext"]:
            client = await self._with_session(row)
            try:
                await client.log_out()
            finally:
                await _disconnect(client)
        await self.c.db.execute("update telegram_accounts set session_ciphertext=null, status='revoked', "
                                "updated_at=now() where id=$1", req.telegram_account_id)
        await self._reply(req, "logged_out", trace_id)
        if self.on_linked:
            await self.on_linked(req.telegram_account_id)

    def _pending(self, req: TgLoginRequest) -> _Pending:
        p = self.pending.get(req.telegram_account_id)
        if p is None:
            raise LookupError("LoginExpired")
        return p


def _friendly(name: str) -> str:
    return {
        "PhoneNumberInvalidError": "That phone number is not valid.",
        "PhoneCodeInvalidError": "That code is wrong. Check it and try again.",
        "PhoneCodeExpiredError": "That code expired. Request a new one.",
        "PasswordHashInvalidError": "Wrong two-step verification password.",
        "FloodWaitError": "Telegram asked us to wait before trying again.",
        "LookupError": "The login timed out. Start again.",
    }.get(name, "Telegram refused the request. Try again in a few minutes.")


async def _disconnect(client) -> None:
    try:
        res = client.disconnect()
        if asyncio.iscoroutine(res):
            await res
    except Exception:
        pass
