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
