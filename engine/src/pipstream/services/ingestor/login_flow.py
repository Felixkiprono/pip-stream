"""Linking a Telegram reader account from the web app.

Laravel (React UI) -> `tg.login.request` -> intake talks to Telegram -> `tg.login.status`.

    send_code   {phone}      -> code_sent {via: app|sms|email|call|...} (where Telegram put the code)
    resend                   -> code_sent again, by Telegram's next method (usually SMS or a call)
    sign_in     {code}       -> linked | password_needed (2FA)
    password    {password}   -> linked
    list_channels            -> channels [{tg_chat_id, title, username, kind}]  (channels and
                                groups the account is in, kind = channel | group)
    test                     -> test_ok {name, username, live} after a note to the account's own
                                Saved Messages (Settings / admin System page: proves the session works)
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


class TelegramApiNotConfigured(Exception):
    pass


def telethon_client_factory(api_id: int, api_hash: str, session: str | None = None, updates: bool = False):
    if not (api_id and api_hash):
        raise TelegramApiNotConfigured()
    from telethon import TelegramClient
    from telethon.sessions import StringSession
    # QR login needs updates (Telegram pushes UpdateLoginToken when the QR is scanned)
    return TelegramClient(StringSession(session or ""), api_id, api_hash, receive_updates=updates, **DEVICE)


# How the session shows in the customer's Telegram (Settings → Devices, and the "New login" notice).
# Telethon's defaults would show the server's CPU/OS ("PIPSTREAM, 1.46.0, arm64, Desktop").
DEVICE = {"device_model": "PipStream", "system_version": "Signal copier (read-only)", "app_version": "1.0",
          "lang_code": "en", "system_lang_code": "en"}


def qr_svg_data_uri(url: str) -> str:
    """The tg://login URL as a QR image (SVG data URI), drawn here so the token never leaves us."""
    import segno
    return segno.make(url, error="m").svg_data_uri(scale=6, border=2, dark="#111827", light="#ffffff")


@dataclass
class _Pending:
    client: object
    phone: str
    phone_code_hash: str | None = None
    started: float = field(default_factory=time.monotonic)
    task: asyncio.Task | None = None  # QR login: waits for the scan


class LoginFlow:
    def __init__(self, c: Context, *, client_factory: Callable | None = None,
                 on_linked: Callable[[str], Awaitable[None]] | None = None,
                 live_source: Callable[[str], object | None] | None = None):
        self.c = c
        s = c.settings
        self.opener = Opener(s.intake_private_key) if s.intake_private_key else None
        self.factory = client_factory or (lambda session=None, updates=False: telethon_client_factory(
            s.telegram_api_id, s.telegram_api_hash, session, updates))
        self.on_linked = on_linked
        self.live_source = live_source or (lambda _acc: None)
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
                if p.task:
                    p.task.cancel()
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
        # one line per step; never the phone number, code or password
        self.c.log.info("telegram login step", extra=ctx(step=req.step, account=req.telegram_account_id))
        try:
            await getattr(self, f"_step_{req.step}")(req, row, ev.trace_id)
        except Exception as exc:  # report a short reason to the web app, never the secrets
            name = type(exc).__name__
            self.c.log.warning("telegram login step failed",
                               extra=ctx(step=req.step, account=req.telegram_account_id, error=name,
                                         detail=str(exc)[:300]))
            await self.c.db.execute("update telegram_accounts set last_error=$2, updated_at=now() where id=$1",
                                    req.telegram_account_id, name)
            await self._reply(req, "error", ev.trace_id, error=_friendly(name, exc))

    async def _step_send_code(self, req: TgLoginRequest, row, trace_id: str) -> None:
        if not req.phone:
            raise ValueError("phone required")
        old = self.pending.pop(req.telegram_account_id, None)
        if old:
            if old.task:
                old.task.cancel()
            await _disconnect(old.client)
        client = self.factory()
        await client.connect()
        sent = await client.send_code_request(req.phone)
        self.pending[req.telegram_account_id] = _Pending(client, req.phone, getattr(sent, "phone_code_hash", None))
        # stand-in Telegram (dev): no real code is sent, so say so on the code screen
        stand_in = bool(getattr(client, "STAND_IN", False))
        info = {"stand_in": True, "code": "12345"} if stand_in else _code_delivery(sent)
        self.c.log.info("telegram code sent", extra=ctx(account=req.telegram_account_id,
                                                        via=(info or {}).get("via"), next=(info or {}).get("next")))
        await self._reply(req, "code_sent", trace_id, phone_hint=req.phone[-3:], info=info)

    async def _step_qr(self, req: TgLoginRequest, row, trace_id: str) -> None:
        """Log in by scanning a QR from the Telegram app (Settings → Devices → Link Desktop
        Device). No login code involved. Replies `qr` (with a new image each time Telegram
        rotates the token), then `linked`, `password_needed` or `error`, all under this request_id."""
        old = self.pending.pop(req.telegram_account_id, None)
        if old:
            if old.task:
                old.task.cancel()
            await _disconnect(old.client)
        try:
            client = self.factory(updates=True)
        except TypeError:  # stand-in clients take no `updates`
            client = self.factory()
        await client.connect()
        qr = await client.qr_login()
        p = _Pending(client, phone="")
        self.pending[req.telegram_account_id] = p
        await self._reply_qr(req, qr, trace_id)
        p.task = asyncio.create_task(self._qr_wait(req, p, qr, trace_id))

    async def _reply_qr(self, req: TgLoginRequest, qr, trace_id: str) -> None:
        expires = getattr(qr, "expires", None)
        await self._reply(req, "qr", trace_id, info={
            "qr": qr_svg_data_uri(qr.url), "url": qr.url,
            "expires": expires.timestamp() if expires else None})
        self.c.log.info("telegram qr shown", extra=ctx(account=req.telegram_account_id))

    async def _qr_wait(self, req: TgLoginRequest, p: _Pending, qr, trace_id: str) -> None:
        try:
            while self.pending.get(req.telegram_account_id) is p:
                try:
                    user = await qr.wait()
                except asyncio.TimeoutError:  # token expired (~30 s): show a fresh QR
                    if time.monotonic() - p.started > LOGIN_TTL_S - 10:
                        raise LookupError("LoginExpired")
                    await qr.recreate()
                    await self._reply_qr(req, qr, trace_id)
                    continue
                p.phone = getattr(user, "phone", None) or ""
                self.c.log.info("telegram qr scanned", extra=ctx(account=req.telegram_account_id))
                await self._finish(req, p, trace_id)
                return
        except asyncio.CancelledError:
            raise
        except Exception as exc:
            name = type(exc).__name__
            if name == "SessionPasswordNeededError":  # scanned; 2FA next (password step uses p.client)
                me_phone = None
                p.started = time.monotonic()
                self.c.log.info("telegram qr scanned: two-step password needed",
                                extra=ctx(account=req.telegram_account_id))
                await self._reply(req, "password_needed", trace_id, phone_hint=me_phone)
                return
            self.c.log.warning("telegram login step failed", extra=ctx(step="qr", account=req.telegram_account_id,
                                                                       error=name, detail=str(exc)[:300]))
            self.pending.pop(req.telegram_account_id, None)
            await _disconnect(p.client)
            await self._reply(req, "error", trace_id, error=_friendly(name, exc))

    async def _step_resend(self, req: TgLoginRequest, row, trace_id: str) -> None:
        """Ask Telegram to deliver the SAME login by its next method (auth.resendCode), instead of
        starting over: a brand-new request would invalidate the code the user may still get."""
        p = self._pending(req)
        if getattr(p.client, "STAND_IN", False):
            sent = None
        else:
            from telethon import functions
            sent = await p.client(functions.auth.ResendCodeRequest(phone_number=p.phone, phone_code_hash=p.phone_code_hash))
            p.phone_code_hash = getattr(sent, "phone_code_hash", p.phone_code_hash)
        info = {"stand_in": True, "code": "12345"} if sent is None else _code_delivery(sent)
        self.c.log.info("telegram code resent", extra=ctx(account=req.telegram_account_id, via=info.get("via"),
                                                          next=info.get("next")))
        await self._reply(req, "code_sent", trace_id, phone_hint=p.phone[-3:], info=info)

    async def _step_sign_in(self, req: TgLoginRequest, row, trace_id: str) -> None:
        p = self._pending(req)
        try:
            await p.client.sign_in(phone=p.phone, code=req.code, phone_code_hash=p.phone_code_hash)
        except Exception as exc:
            if type(exc).__name__ == "SessionPasswordNeededError":
                self.c.log.info("telegram sign_in: two-step password needed", extra=ctx(account=req.telegram_account_id))
                await self._reply(req, "password_needed", trace_id)
                return
            raise
        await self._finish(req, p, trace_id)

    async def _step_password(self, req: TgLoginRequest, row, trace_id: str) -> None:
        p = self._pending(req)
        await p.client.sign_in(password=req.password)
        await self._finish(req, p, trace_id)

    async def _finish(self, req: TgLoginRequest, p: _Pending, trace_id: str) -> None:
        if not p.phone:  # QR + 2FA: learn the phone from the account itself
            try:
                me = await p.client.get_me()
                p.phone = getattr(me, "phone", None) or ""
            except Exception:
                pass
        session = p.client.session.save()
        self.pending.pop(req.telegram_account_id, None)
        await _disconnect(p.client)
        await self.c.db.execute(
            "update telegram_accounts set session_ciphertext=$2, phone_hint=$3, status='active', last_error=null, "
            "updated_at=now() where id=$1", req.telegram_account_id, self.opener.seal(session), p.phone[-3:])
        await self.c.db.execute("insert into audit_log (actor, action, entity, entity_id) values "
                                "('intake', 'telegram_linked', 'telegram_account', $1)", req.telegram_account_id)
        del session
        self.c.log.info("telegram account linked", extra=ctx(account=req.telegram_account_id))
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
                # broadcast channels, supergroups (is_channel) and basic groups (is_group);
                # never private chats
                if not (getattr(d, "is_channel", False) or getattr(d, "is_group", False)):
                    continue
                ent = getattr(d, "entity", None)
                kind = "channel" if getattr(ent, "broadcast", False) else "group"
                chans.append({"tg_chat_id": d.id, "title": d.name, "username": getattr(ent, "username", None),
                              "kind": kind})
        finally:
            await _disconnect(client)
        self.c.log.info("telegram chats listed", extra=ctx(
            account=req.telegram_account_id, channels=sum(c["kind"] == "channel" for c in chans),
            groups=sum(c["kind"] == "group" for c in chans)))
        await self._reply(req, "channels", trace_id, channels=chans)

    async def _step_test(self, req: TgLoginRequest, row, trace_id: str) -> None:
        stamp = time.strftime("%Y-%m-%d %H:%M UTC", time.gmtime())
        text = (f"PipStream test ✓ This Telegram account is connected and PipStream can read your channels.\n"
                f"Connection check at {stamp}. Only you can see this note.")
        src = self.live_source(req.telegram_account_id)
        if src is not None:  # the reader that is already connected: also proves it is live
            me = await src.whoami()
            await src.send_to_saved(text)
            live = True
        else:
            client = await self._with_session(row)
            try:
                u = await client.get_me()
                me = {"name": " ".join(x for x in (getattr(u, "first_name", None), getattr(u, "last_name", None)) if x),
                      "username": getattr(u, "username", None)}
                await client.send_message("me", text)
            finally:
                await _disconnect(client)
            live = False
        await self._reply(req, "test_ok", trace_id, info={**me, "live": live})

    async def _step_logout(self, req: TgLoginRequest, row, trace_id: str) -> None:
        if row["session_ciphertext"]:
            client = await self._with_session(row)
            try:
                await client.log_out()
            finally:
                await _disconnect(client)
        await self.c.db.execute("update telegram_accounts set session_ciphertext=null, status='revoked', "
                                "updated_at=now() where id=$1", req.telegram_account_id)
        self.c.log.info("telegram account logged out", extra=ctx(account=req.telegram_account_id))
        await self._reply(req, "logged_out", trace_id)
        if self.on_linked:
            await self.on_linked(req.telegram_account_id)

    def _pending(self, req: TgLoginRequest) -> _Pending:
        p = self.pending.get(req.telegram_account_id)
        if p is None:
            raise LookupError("LoginExpired")
        return p


_VIA = {
    "SentCodeTypeApp": "app", "SentCodeTypeSms": "sms", "SentCodeTypeFirebaseSms": "sms",
    "SentCodeTypeSmsWord": "sms", "SentCodeTypeSmsPhrase": "sms", "SentCodeTypeCall": "call",
    "SentCodeTypeFlashCall": "call", "SentCodeTypeMissedCall": "missed_call",
    "SentCodeTypeEmailCode": "email", "SentCodeTypeSetUpEmailRequired": "setup_email",
    "SentCodeTypeFragmentSms": "fragment",
    "CodeTypeSms": "sms", "CodeTypeCall": "call", "CodeTypeFlashCall": "call",
    "CodeTypeMissedCall": "missed_call", "CodeTypeFragmentSms": "fragment",
}


def _code_delivery(sent) -> dict:
    """Where Telegram delivered the login code, so the screen can say where to look."""
    t = getattr(sent, "type", None)
    nxt = getattr(sent, "next_type", None)
    return {"via": _VIA.get(type(t).__name__, type(t).__name__) if t is not None else None,
            "length": getattr(t, "length", None),
            "email": getattr(t, "email_pattern", None),
            "next": _VIA.get(type(nxt).__name__) if nxt is not None else None,
            "timeout": getattr(sent, "timeout", None)}


def _friendly(name: str, exc: Exception | None = None) -> str:
    wait = getattr(exc, "seconds", None)
    if name == "FloodWaitError" and wait:
        return f"Telegram asked us to wait {int(wait) // 60} min {int(wait) % 60} s before trying again."
    known = {
        "PhoneNumberInvalidError": "That phone number is not valid.",
        "PhoneCodeInvalidError": "That code is wrong. Check it and try again.",
        "PhoneCodeExpiredError": "That code expired. Request a new one.",
        "PasswordHashInvalidError": "Wrong two-step verification password.",
        "FloodWaitError": "Telegram asked us to wait before trying again.",
        "LookupError": "The login timed out. Start again.",
        "TelegramApiNotConfigured": "The server has no Telegram API ID/hash yet (PS_TELEGRAM_API_ID / _HASH).",
        "ApiIdInvalidError": "The server's Telegram API ID/hash are not valid. Check them at my.telegram.org.",
        "PhoneNumberBannedError": "Telegram has banned this phone number.",
        "PhoneNumberUnoccupiedError": "There is no Telegram account on this number yet.",
        "PhoneNumberFloodError": "Too many code requests for this number. Wait a while, then try again.",
        "PhoneCodeEmptyError": "Enter the code Telegram sent you.",
        "SessionPasswordNeededError": "This account has two-step verification. Enter that password.",
        "AuthRestartError": "Telegram asked to restart the login. Request a new code.",
        "SendCodeUnavailableError": "Telegram has no other way to send the code. Use the last code, or start again later.",
        "PasswordTooFreshError": "The two-step password was changed recently. Telegram blocks new logins for a while.",
        "ConnectionError": "Could not reach Telegram from the server. Check its internet connection.",
        "TimeoutError": "Telegram took too long to answer. Try again.",
    }
    if name in known:
        return known[name]
    return f"Telegram refused the request ({name}). Try again in a few minutes."


async def _disconnect(client) -> None:
    try:
        res = client.disconnect()
        if asyncio.iscoroutine(res):
            await res
    except Exception:
        pass
