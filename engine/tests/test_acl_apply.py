"""Redis without an ACL file (NanoDeploi's managed Redis: only --requirepass): the engine creates
the per-service users itself, with the same permissions the ACL file would give."""
import os
import shutil
import socket
import subprocess
import time

import pytest
import redis

from pipstream.apps.engine import child_env
from pipstream.common.permissions import admin_url_from_env, apply_acl


def _free_port() -> int:
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    port = s.getsockname()[1]
    s.close()
    return port


@pytest.fixture
def plain_redis():
    if not shutil.which("redis-server"):
        pytest.skip("redis-server not installed")
    port = _free_port()
    proc = subprocess.Popen(["redis-server", "--port", str(port), "--requirepass", "site-pw", "--save", "",
                             "--appendonly", "no"], stdout=subprocess.DEVNULL, stderr=subprocess.STDOUT)
    for _ in range(50):
        try:
            socket.create_connection(("127.0.0.1", port), 0.1).close()
            break
        except OSError:
            time.sleep(0.1)
    yield port
    proc.terminate()
    proc.wait()


def test_apply_acl_on_requirepass_redis(plain_redis, passwords):
    port = plain_redis
    env = {f"PS_REDIS_PASSWORD_{k.upper()}": v for k, v in passwords.items()}
    env |= {"REDIS_URL": f"redis://:site-pw@127.0.0.1:{port}/0", "PS_REDIS_APPLY_ACL": "1"}
    url = admin_url_from_env(env)
    assert url == env["REDIS_URL"]
    assert apply_acl(url, env) == ["intake", "risk", "executor", "laravel"]
    assert apply_acl(url, env)  # idempotent: a second run resets and re-creates

    def as_(svc):
        return redis.Redis.from_url(f"redis://{svc}:{passwords[svc]}@127.0.0.1:{port}/0", decode_responses=True)

    as_("risk").xadd("ps:stream:trade.intent", {"x": "1"})          # risk may write intents
    with pytest.raises(redis.exceptions.NoPermissionError):
        as_("intake").xadd("ps:stream:trade.intent", {"x": "1"})    # intake may not
    with pytest.raises(redis.exceptions.NoPermissionError):
        as_("laravel").xadd("ps:stream:trade.intent", {"x": "1"})   # nor the web app
    as_("laravel").set("laravel:cache:k", "v")                      # Laravel's own keys
    # the host's default user keeps working (NanoDeploi's health checks and tools use it)
    assert redis.Redis.from_url(env["REDIS_URL"]).ping()


def test_admin_url_and_database_url_fallbacks():
    assert admin_url_from_env({"REDIS_URL": "redis://x"}) is None            # off unless asked
    assert admin_url_from_env({"PS_REDIS_ADMIN_URL": "redis://a"}) == "redis://a"
    env = {"PS_REDIS_HOST": "redis", "PS_REDIS_PASSWORD_RISK": "pw", "REDIS_URL": "redis://:site@redis",
           "PS_DATABASE_URL": "postgresql://db"}
    out = child_env("risk", env)
    assert out["PS_REDIS_URL"] == "redis://risk:pw@redis:6379/0"
    assert "REDIS_URL" not in out                                           # workers never see the admin password


def test_engine_main_uses_database_url(monkeypatch):
    import pipstream.apps.engine as eng
    seen = {}

    class Fake:
        def __init__(self, workers, env):
            seen["env"] = env

        async def run(self):
            return 0

    monkeypatch.setattr(eng, "Supervisor", Fake)
    monkeypatch.delenv("PS_DATABASE_URL", raising=False)
    monkeypatch.setenv("DATABASE_URL", "postgresql://u:p@pgsql:5432/db")
    with pytest.raises(SystemExit):
        eng.main()
    assert seen["env"]["PS_DATABASE_URL"] == "postgresql://u:p@pgsql:5432/db"


def test_laravel_acl_template_matches_permissions():
    """resources/redis/laravel.acl (read by `php artisan pipstream:redis-user`) must stay in sync."""
    from pathlib import Path

    from pipstream.common.permissions import acl_line
    path = Path(__file__).resolve().parents[2] / "resources" / "redis" / "laravel.acl"
    want = " ".join(acl_line("laravel", "__PASSWORD__").split()[2:])
    assert path.read_text().strip() == want, "regenerate resources/redis/laravel.acl from permissions.py"
