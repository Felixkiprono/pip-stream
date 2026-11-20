"""A stand-in Telegram for local development and demos (PS_INGESTOR_FAKE_TELEGRAM=1).

The web app's "Connect Telegram" screens then work without a real Telegram account:

    any phone number      -> code_sent
    code 12345            -> linked
    code 11111            -> password_needed, then password "demo" -> linked
    anything else         -> "That code is wrong"

The channel list is a fixed set of sample channels. Never enable this in production: the
engine refuses to start with it when PS_ENV=production.
"""
from __future__ import annotations

SAMPLE_CHANNELS = [  # (id, title, username[, kind]); kind defaults to channel
    (-1001000000001, "Gold Signals Room", "goldsignalsroom"),
    (-1001000000002, "FX Desk Daily", "fxdeskdaily"),
    (-1001000000003, "Nairobi Pips", "nairobipips"),
    (-1001000000004, "Synthetic Indices Hub", "synthhub"),
    (-1001000000005, "Swing Trades Weekly", None),
    (-1001000000006, "Crypto Majors", "cryptomajors"),
    (-1001000000007, "VIP Gold Group", None, "group"),  # supergroup
    (-4000000008, "Pips Study Group", None, "group"),  # basic group
]


class SessionPasswordNeededError(Exception):
    pass


class PhoneCodeInvalidError(Exception):
    pass


class PasswordHashInvalidError(Exception):
    pass


class _Session:
    def __init__(self, s: str | None):
        self.s = s

    def save(self) -> str:
        return self.s or "fake-telegram-session"


class _Dialog:
    def __init__(self, i, name, username, kind="channel"):
        basic_group = kind == "group" and not str(i).startswith("-100")
        self.id, self.name = i, name
        self.is_channel, self.is_group = not basic_group, kind == "group"
        self.entity = type("E", (), {"username": username, "broadcast": kind == "channel"})()


class _FakeQr:
    """Stand-in QR login: "scanned" 4 s after it is shown."""
    url = "tg://login?token=c3RhbmQtaW4"

    @property
    def expires(self):
        import datetime
        return datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(seconds=30)

    async def wait(self, timeout=None):
        import asyncio
        await asyncio.sleep(4)
        return type("U", (), {"phone": "254700000000"})()

    async def recreate(self):
        pass


class FakeTelegramClient:
    STAND_IN = True

    def __init__(self, session: str | None = None):
        self.session = _Session(session)

    async def connect(self):
        pass

    def disconnect(self):
        pass

    async def send_code_request(self, phone):
        return type("Sent", (), {"phone_code_hash": "fake"})()

    async def sign_in(self, phone=None, code=None, phone_code_hash=None, password=None):
        if password is not None:
            if password != "demo":
                raise PasswordHashInvalidError()
            return
        if code == "11111":
            raise SessionPasswordNeededError()
        if code != "12345":
            raise PhoneCodeInvalidError()

    async def iter_dialogs(self):
        for d in SAMPLE_CHANNELS:
            yield _Dialog(*d)

    async def log_out(self):
        pass

    async def qr_login(self):
        return _FakeQr()

    async def get_me(self):
        return type("Me", (), {"first_name": "Stand-in", "last_name": "account", "username": "standin"})()

    async def send_message(self, to, text):
        pass


# ---------------------------------------------------------------- reading posts (stand-in)
def inbox_key(prefix: str) -> str:
    return f"{prefix}:fake:tg:inbox"


def saved_key(prefix: str) -> str:
    return f"{prefix}:fake:tg:saved"


class FakeTelegramSource:
    """Stand-in for TelethonSource: every linked account "receives" the posts pushed with
    `pipstream fake-post`, and its Saved Messages notes land in a Redis list that
    `pipstream fake-saved` prints. Lets the whole read -> parse -> confirm path run locally."""

    def __init__(self, account_id: str, redis, prefix: str):
        self.account_id, self.redis, self.prefix = account_id, redis, prefix
        self._task = None

    async def start(self, on_post) -> None:
        import asyncio
        import json
        import logging
        from datetime import datetime, timezone

        from ..services.ingestor.sources.base import RawPost

        log = logging.getLogger("pipstream.ingestor.fake")
        # one inbox per account, so several fake accounts each see every post
        key = f"{inbox_key(self.prefix)}:{self.account_id}"

        async def loop():
            while True:
                try:
                    raw = await self.redis.lpop(key)  # poll: a blocking pop trips the client's read timeout
                    if raw is None:
                        await asyncio.sleep(0.3)
                        continue
                    d = json.loads(raw)
                    await on_post(self.account_id, RawPost(
                        tg_chat_id=int(d["tg_chat_id"]), message_id=int(d["message_id"]), text=d.get("text", ""),
                        reply_to_id=d.get("reply_to_id"), is_edit=bool(d.get("edit")), has_media=False,
                        posted_at=datetime.now(timezone.utc)))
                except asyncio.CancelledError:
                    raise
                except Exception:
                    log.exception("fake post failed")
                    await asyncio.sleep(1)

        self._task = asyncio.create_task(loop())
        log.info("fake telegram source connected", extra={"ctx": {"telegram_account_id": self.account_id}})

    async def whoami(self) -> dict:
        return {"name": "Stand-in account", "username": "standin"}

    async def send_to_saved(self, text: str) -> None:
        import json
        import time
        await self.redis.rpush(saved_key(self.prefix), json.dumps(
            {"telegram_account_id": self.account_id, "text": text, "ts": time.time()}))
        await self.redis.ltrim(saved_key(self.prefix), -200, -1)

    async def stop(self) -> None:
        if self._task:
            self._task.cancel()
            self._task = None
