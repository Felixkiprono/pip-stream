"""Test fixtures. Integration tests need Postgres + redis-server:
    source scripts/test-infra.sh && pytest
(or set PS_TEST_DATABASE_URL and have redis-server on PATH). They are skipped otherwise."""
from __future__ import annotations

import asyncio
import os
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import uuid

import pytest

from pipstream.common.permissions import SERVICES, render_acl
from pipstream.common.signing import generate_keypair
from pipstream.common.vault import SecretBox

PG = os.environ.get("PS_TEST_DATABASE_URL")


def _free_port() -> int:
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


@pytest.fixture(scope="session")
def passwords():
    return {svc: secrets.token_urlsafe(12) for svc in ["admin", *SERVICES]}


@pytest.fixture(scope="session")
def acl_redis(passwords):
    """redis-server started with the generated ACL file (every service its own user)."""
    if not shutil.which("redis-server"):
        pytest.skip("redis-server not installed")
    d = tempfile.mkdtemp()
    env = {f"PS_REDIS_PASSWORD_{k.upper()}": v for k, v in passwords.items()}
    acl = os.path.join(d, "users.acl")
    with open(acl, "w") as f:
        f.write(render_acl(env))
    port = _free_port()
    proc = subprocess.Popen(["redis-server", "--port", str(port), "--aclfile", acl, "--save", "", "--appendonly", "no"],
                            stdout=subprocess.DEVNULL, stderr=subprocess.STDOUT)
    for _ in range(50):
        try:
            socket.create_connection(("127.0.0.1", port), 0.1).close()
            break
        except OSError:
            time.sleep(0.1)
    yield {"port": port, "url": lambda svc: f"redis://{svc}:{passwords[svc]}@127.0.0.1:{port}/0"}
    proc.terminate()
    proc.wait()


@pytest.fixture
async def test_db():
    if not PG:
        pytest.skip("PS_TEST_DATABASE_URL not set")
    import asyncpg
    name = "ps_test_" + uuid.uuid4().hex[:8]
    admin = await asyncpg.connect(PG)
    await admin.execute(f"create database {name}")
    await admin.close()
    base = PG.rsplit("/", 1)[0]
    yield f"{base}/{name}"
    admin = await asyncpg.connect(PG)
    await admin.execute(f"drop database if exists {name} with (force)")
    await admin.close()


@pytest.fixture(scope="session")
def keys():
    priv, pub = generate_keypair()
    return {"intent_signing_key": priv, "intent_verify_key": pub, "secrets_master_keys": SecretBox.generate_key()}


async def wait_for(pred, timeout=15.0, every=0.1):
    t0 = time.monotonic()
    while time.monotonic() - t0 < timeout:
        v = await pred()
        if v:
            return v
        await asyncio.sleep(every)
    raise AssertionError("condition not met in time")
