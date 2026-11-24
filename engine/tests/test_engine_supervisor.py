"""The engine runs intake, risk and executor as child processes; each child must get only
its own secrets and its own Redis user."""
from pipstream.apps.engine import ORDER, child_env

ENV = {
    "PATH": "/usr/bin", "PS_DATABASE_URL": "postgresql://x", "PS_REDIS_HOST": "redis", "PS_REDIS_PORT": "6379",
    "PS_REDIS_PASSWORD_INTAKE": "pi", "PS_REDIS_PASSWORD_RISK": "pr", "PS_REDIS_PASSWORD_EXECUTOR": "pe",
    "PS_INTAKE_PRIVATE_KEY": "IK", "PS_TELEGRAM_API_HASH": "TH", "PS_INTENT_SIGNING_KEY": "SK",
    "PS_INTENT_VERIFY_KEY": "VK", "PS_EXECUTOR_PRIVATE_KEY": "EK", "PS_DERIV_TOKEN": "DT",
    "PS_REDIS_PASSWORD_ADMIN": "root", "AWS_SECRET": "nope",
}


def test_pipeline_order():
    assert ORDER == ("executor", "risk", "intake")


def test_each_worker_gets_only_its_own_secrets():
    intake, risk, ex = (child_env(w, ENV) for w in ("intake", "risk", "executor"))
    assert intake["PS_INTAKE_PRIVATE_KEY"] == "IK" and "PS_INTENT_SIGNING_KEY" not in intake
    assert "PS_EXECUTOR_PRIVATE_KEY" not in intake and "PS_DERIV_TOKEN" not in intake
    assert risk["PS_INTENT_SIGNING_KEY"] == "SK" and set(risk) & {"PS_INTAKE_PRIVATE_KEY", "PS_EXECUTOR_PRIVATE_KEY",
                                                                     "PS_TELEGRAM_API_HASH", "PS_DERIV_TOKEN"} == set()
    assert ex["PS_EXECUTOR_PRIVATE_KEY"] == "EK" and ex["PS_DERIV_TOKEN"] == "DT" and "PS_INTENT_SIGNING_KEY" not in ex
    for e in (intake, risk, ex):
        assert "PS_REDIS_PASSWORD_ADMIN" not in e and "AWS_SECRET" not in e and e["PS_DATABASE_URL"]
    assert intake["PS_REDIS_URL"] == "redis://intake:pi@redis:6379/0"
    assert risk["PS_REDIS_URL"] == "redis://risk:pr@redis:6379/0"
    assert ex["PS_REDIS_URL"] == "redis://executor:pe@redis:6379/0"


def test_redis_login_falls_back_to_the_hosts_own():
    from pipstream.apps.engine import worker_redis_url
    host = {"REDIS_HOST": "redis", "REDIS_PORT": "6379", "REDIS_PASSWORD": "p/w"}
    # nothing PipStream-specific: the platform's login (NanoDeploi shares REDIS_HOST/PASSWORD)
    assert worker_redis_url("risk", host) == "redis://:p%2Fw@redis:6379/0"
    # a half-filled PS_REDIS_URL ("redis") is ignored instead of breaking the login
    assert worker_redis_url("risk", {**host, "PS_REDIS_URL": "redis"}) == "redis://:p%2Fw@redis:6379/0"
    assert worker_redis_url("risk", {**host, "REDIS_URL": "redis://:x@r:1/0"}) == "redis://:x@r:1/0"
    # per-worker ACL users win, on the host's Redis when PS_REDIS_HOST is not set
    assert worker_redis_url("risk", {**host, "PS_REDIS_PASSWORD_RISK": "rk"}) == "redis://risk:rk@redis:6379/0"
    # local dev: one open Redis
    assert worker_redis_url("risk", {"PS_REDIS_URL": "redis://127.0.0.1:56379/0"}) == "redis://127.0.0.1:56379/0"


def test_acl_admin_login_comes_from_redis_host_and_password():
    from pipstream.common.permissions import admin_url_from_env
    env = {"PS_REDIS_APPLY_ACL": "1", "REDIS_HOST": "redis", "REDIS_PASSWORD": "pw"}
    assert admin_url_from_env(env) == "redis://:pw@redis:6379/0"
    assert admin_url_from_env({"REDIS_HOST": "redis", "REDIS_PASSWORD": "pw"}) is None
