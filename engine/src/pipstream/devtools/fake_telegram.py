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

SAMPLE_CHANNELS = [
    (-1001000000001, "Gold Signals Room", "goldsignalsroom"),
    (-1001000000002, "FX Desk Daily", "fxdeskdaily"),
    (-1001000000003, "Nairobi Pips", "nairobipips"),
    (-1001000000004, "Synthetic Indices Hub", "synthhub"),
    (-1001000000005, "Swing Trades Weekly", None),
    (-1001000000006, "Crypto Majors", "cryptomajors"),
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
    def __init__(self, i, name, username):
        self.id, self.name, self.is_channel = i, name, True
        self.entity = type("E", (), {"username": username})()


class FakeTelegramClient:
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
