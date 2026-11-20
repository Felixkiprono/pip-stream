"""Telegram account linking driven by the web app, and the parse-preview endpoint.
Telegram itself is replaced by a fake client; the rest (Redis ACL users, Postgres, sealed boxes)
is real. Needs Postgres + redis-server."""
from __future__ import annotations

import asyncio

import httpx
import pytest
from redis.asyncio import Redis

from pipstream.apps.preview import build_app
from pipstream.common.bus import Bus
from pipstream.common.db import create_pool, migrate
from pipstream.common.events import TgLoginRequest, Topic
from pipstream.common.service import build_context, close_context
from pipstream.common.settings import Settings
from pipstream.common.vault import Opener
from pipstream.services.ingestor.login_flow import LoginFlow
from pipstream.services.parser.service import ParserService

from .conftest import wait_for


class SessionPasswordNeededError(Exception):
    pass


class FakeSession:
    def save(self):
        return "STRING-SESSION-SECRET"


class FakeDialog:
    def __init__(self, i, name):
        self.id, self.name, self.is_channel = i, name, True
        self.entity = type("E", (), {"username": name.lower()})()


class FakeClient:
    """Mimics the Telethon calls the login flow makes."""
    created: list["FakeClient"] = []

    def __init__(self, session=None):
        self.session_in, self.session = session, FakeSession()
        self.signed_in, self.logged_out = False, False
        FakeClient.created.append(self)

    async def connect(self):
        pass

    async def disconnect(self):
        pass

    async def send_code_request(self, phone):
        return type("Sent", (), {"phone_code_hash": "hash123"})()

    async def sign_in(self, phone=None, code=None, phone_code_hash=None, password=None):
        if password is None and code == "11111":
            raise SessionPasswordNeededError()
        if password is not None and password != "2fa-pass":
            raise type("PasswordHashInvalidError", (Exception,), {})()
        self.signed_in = True

    async def iter_dialogs(self):
        for d in (FakeDialog(-1001, "Gold Room"), FakeDialog(-1002, "FX Desk")):
            yield d

    async def log_out(self):
        self.logged_out = True


@pytest.fixture
async def intake(acl_redis, test_db, keys):
    pool = await create_pool(test_db)
    await migrate(pool)
    await pool.execute("insert into users (id, email, role) values ('usr_a','a@x.io','customer'), "
                       "('usr_b','b@x.io','customer')")
    await pool.execute("insert into telegram_accounts (id, user_id) values ('tga_1','usr_a')")
    admin = Redis.from_url(acl_redis["url"]("admin"), decode_responses=True)
    await admin.delete("ps:stream:tg.login.request", "ps:stream:tg.login.status")
    await admin.aclose()
    settings = Settings(redis_url=acl_redis["url"]("intake"), database_url=test_db, **keys)
    c = await build_context("intake", settings)
    linked: list[str] = []

    async def on_linked(acc):
        linked.append(acc)

    flow = LoginFlow(c, client_factory=FakeClient, on_linked=on_linked)
    task = asyncio.create_task(c.bus.subscribe([Topic.TG_LOGIN_REQUEST], flow.handle, consumer="t",
                                                block_ms=100, concurrency=1, max_deliveries=1))
    web = Bus(Redis.from_url(acl_redis["url"]("laravel"), decode_responses=True), "laravel")
    statuses: list[dict] = []

    async def collect():
        r = Redis.from_url(acl_redis["url"]("admin"), decode_responses=True)
        last = "0"
        while True:
            resp = await r.xread({"ps:stream:tg.login.status": last}, block=100)
            for _, entries in resp or []:
                for mid, f in entries:
                    last = mid
                    import json
                    statuses.append(json.loads(f["e"])["payload"])

    col = asyncio.create_task(collect())
    yield {"web": web, "statuses": statuses, "db": pool, "keys": keys, "linked": linked, "ctx": c}
    c.stop.set()
    c.bus.stop()
    col.cancel()
    await asyncio.wait([task], timeout=3)
    await close_context(c)
    await web.redis.aclose()
    await pool.close()


async def _ask(env, step, **kw):
    n = len(env["statuses"])
    await env["web"].publish(Topic.TG_LOGIN_REQUEST, TgLoginRequest(
        request_id=f"req{n}", telegram_account_id=kw.pop("acc", "tga_1"), user_id=kw.pop("user", "usr_a"),
        step=step, **kw))
    await wait_for(lambda: _async(len(env["statuses"]) > n), timeout=5)
    return env["statuses"][-1]


async def _async(v):
    return v


async def test_web_login_with_2fa_stores_sealed_session(intake):
    env = intake
    assert (await _ask(env, "send_code", phone="+254700000123"))["status"] == "code_sent"
    assert (await _ask(env, "sign_in", code="11111"))["status"] == "password_needed"
    bad = await _ask(env, "password", password="nope")
    assert bad["status"] == "error" and "password" in bad["error"].lower()
    ok = await _ask(env, "password", password="2fa-pass")
    assert ok["status"] == "linked" and ok["phone_hint"] == "123"

    row = await env["db"].fetchrow("select * from telegram_accounts where id='tga_1'")
    assert row["status"] == "active" and row["phone_hint"] == "123"
    assert "STRING-SESSION" not in row["session_ciphertext"]                  # stored sealed
    assert Opener(env["keys"]["intake_private_key"]).open(row["session_ciphertext"]) == "STRING-SESSION-SECRET"
    assert env["linked"] == ["tga_1"]                                         # ingestor told to start reading

    chans = await _ask(env, "list_channels")
    assert [c["title"] for c in chans["channels"]] == ["Gold Room", "FX Desk"]
    assert FakeClient.created[-1].session_in == "STRING-SESSION-SECRET"      # opened only in intake

    out = await _ask(env, "logout")
    assert out["status"] == "logged_out"
    row = await env["db"].fetchrow("select * from telegram_accounts where id='tga_1'")
    assert row["status"] == "revoked" and row["session_ciphertext"] is None


async def test_login_refuses_someone_elses_account(intake):
    st = await _ask(intake, "send_code", phone="+254700000999", user="usr_b")
    assert st["status"] == "error" and st["error"] == "unknown telegram account"


async def test_parse_preview_endpoint(acl_redis, test_db, keys):
    pool = await create_pool(test_db)
    await migrate(pool)
    c = await build_context("intake", Settings(redis_url=acl_redis["url"]("intake"), database_url=test_db, **keys))
    par = ParserService(c)
    transport = httpx.ASGITransport(app=build_app(par))
    async with httpx.AsyncClient(transport=transport, base_url="http://intake") as h:
        r = await h.post("/parse-preview", json={"text": "XAUUSD BUY 2348-2350 SL 2340 TP 2360 / 2375"})
        body = r.json()
        assert body["status"] == "parsed"
        assert body["signal"]["symbol"] == "XAUUSD" and body["signal"]["tps"] == [2360, 2375]
        r = await h.post("/parse-preview", json={"text": "Good morning traders!"})
        assert r.json()["status"] == "rejected"
    assert await pool.fetchval("select count(*) from signals") == 0   # preview never stores
    await close_context(c)
    await pool.close()
