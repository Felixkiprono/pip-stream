"""Single source of truth for who may publish / consume which topic and touch which keys.

Used twice:
  1. Client side: `Bus` refuses to publish a topic its service does not own.
  2. Server side: `render_acl()` generates the Redis ACL file, so even a compromised
     service cannot XADD to a stream it does not own (e.g. only the risk engine can
     write `trade.intent`). The signature check in the router is the second layer.

Render with:  python -m pipstream.common.permissions > users.acl
(passwords come from PS_REDIS_PASSWORD_<SERVICE> env vars).
"""
from __future__ import annotations

import os
import sys
from dataclasses import dataclass, field

from .events import Topic as T


@dataclass(frozen=True)
class ServicePerms:
    publish: frozenset[T]
    consume: frozenset[T] = frozenset()
    broadcast: frozenset[T] = frozenset()  # read-without-group (every replica sees it)
    rw_keys: tuple[str, ...] = ()
    ro_keys: tuple[str, ...] = ()
    extra: tuple[str, ...] = field(default_factory=tuple)


SERVICES: dict[str, ServicePerms] = {
    # Python engine ---------------------------------------------------------------
    # intake = Telegram ingestor + parser (one process, holds Telegram sessions)
    "intake": ServicePerms(
        publish=frozenset({T.TG_MESSAGE_NEW, T.TG_MESSAGE_EDITED, T.SIGNAL_PARSED, T.SIGNAL_REJECTED,
                           T.TG_LOGIN_STATUS}),
        consume=frozenset({T.TG_MESSAGE_NEW, T.TG_MESSAGE_EDITED, T.TG_LOGIN_REQUEST}),
        broadcast=frozenset({T.CONFIG_CHANGED}),
        rw_keys=("ps:dedupe:*", "ps:lease:*", "ps:editver:*"),
        # login requests carry the phone, code and 2FA password: intake deletes each once handled
        extra=("(+xdel ~ps:stream:tg.login.request)",),
    ),
    # risk = copy rules, sizing, limits; the ONLY writer of trade.intent (holds signing key)
    "risk": ServicePerms(
        publish=frozenset({T.TRADE_INTENT, T.RISK_LIMIT_HIT}),
        consume=frozenset({T.SIGNAL_PARSED}),
        broadcast=frozenset({T.CONFIG_CHANGED, T.BILLING_CHANGED}),
        ro_keys=("ps:acct:*", "ps:quote:*", "ps:killswitch:*"),
    ),
    # executor = execution router + position tracker (holds broker credentials)
    "executor": ServicePerms(
        publish=frozenset({T.TRADE_EXECUTED, T.TRADE_FAILED, T.ACCOUNT_SNAPSHOT, T.TRADE_CLOSED}),
        consume=frozenset({T.TRADE_INTENT, T.TRADE_EXECUTED, T.ACCOUNT_SNAPSHOT}),
        broadcast=frozenset({T.CONFIG_CHANGED}),
        rw_keys=("ps:idem:*", "ps:acct:*", "ps:quote:*", "ps:paper:*"),
        ro_keys=("ps:killswitch:*", "ps:eares:*"),
        # EA bridge: only the executor can queue commands for a customer's MetaTrader terminal
        extra=("(+xadd +xtrim +xrevrange ~ps:acct:*:ea:cmd)",),
    ),
    # Laravel core -------------------------------------------------------------------
    # Web app, billing, notifications, kill switch. Its queue/cache/session keys live under
    # "laravel:" (see LARAVEL_ACL_EXTRA); it can never write trade.intent.
    "laravel": ServicePerms(
        publish=frozenset({T.CONFIG_CHANGED, T.BILLING_CHANGED, T.TG_LOGIN_REQUEST}),
        consume=frozenset({T.TRADE_EXECUTED, T.TRADE_FAILED, T.TRADE_CLOSED, T.RISK_LIMIT_HIT,
                           T.SIGNAL_REJECTED, T.TG_LOGIN_STATUS}),
        rw_keys=("ps:killswitch:*", "ps:eares:*", "ps:quote:*"),
        ro_keys=("ps:health:*", "ps:acct:*", "ps:lease:*"),
        # EA bridge: hands queued commands to the EA (read only) and stores its answers (ps:eares:*)
        extra=("(+@all -@admin -@dangerous ~laravel:*)", "(+xrevrange %R~ps:acct:*:ea:cmd)"),
    ),
}

_CONSUME_CMDS = "+xreadgroup +xack +xautoclaim +xgroup|create +xinfo|groups +xpending +xlen"
_BROADCAST_CMDS = "+xread +xrevrange +xlen"
_KV_CMDS = ("+get +set +del +exists +expire +pexpire +pttl +ttl +incr +incrby +incrbyfloat +mget "
            "+hget +hset +hdel +hgetall +hincrby +hincrbyfloat +hkeys +eval +evalsha +script|load")


def stream_key(prefix: str, topic: str) -> str:
    return f"{prefix}:stream:{topic}"


def acl_line(service: str, password: str, prefix: str = "ps") -> str:
    p = SERVICES[service]
    parts = [f"user {service} on >{password} resetkeys resetchannels -@all",
             "+ping +hello +auth +client|setname +info"]
    if p.consume:
        keys = " ".join(f"~{stream_key(prefix, t)}" for t in sorted(p.consume))
        parts.append(f"({_CONSUME_CMDS} {keys})")
    if p.broadcast:
        keys = " ".join(f"%R~{stream_key(prefix, t)}" for t in sorted(p.broadcast))
        parts.append(f"({_BROADCAST_CMDS} {keys})")
    pub = sorted(p.publish) + ["dlq"]
    parts.append("(+xadd " + " ".join(f"~{stream_key(prefix, t)}" for t in pub) + ")")
    own = [f"~{prefix}:health:{service}", f"~{prefix}:health:{service}:*", f"~{prefix}:deliveries:{service}", *[f"~{k}" for k in p.rw_keys]]
    own += [f"%R~{k}" for k in p.ro_keys]
    parts.append(f"({_KV_CMDS} {' '.join(own)})")
    parts.extend(p.extra)
    return " ".join(parts)


def apply_acl(admin_url: str, env: dict[str, str] | None = None, prefix: str = "ps") -> list[str]:
    """Create or update the per-service Redis users on a running server (ACL SETUSER), for hosts
    whose Redis can't load an ACL file (e.g. NanoDeploi's managed Redis, which only takes
    --requirepass). Connects with the admin/default credentials in `admin_url`. Users made this
    way live in memory only, so the engine re-applies them every time it starts a worker.
    The default user is left as the host configured it."""
    import redis as _redis

    env = dict(os.environ if env is None else env)
    r = _redis.Redis.from_url(admin_url, socket_timeout=5)
    done = []
    try:
        for svc in SERVICES:
            pw = env.get(f"PS_REDIS_PASSWORD_{svc.upper()}")
            if not pw:
                raise SystemExit(f"missing PS_REDIS_PASSWORD_{svc.upper()}")
            rules = acl_line(svc, pw, prefix).split()[2:]  # drop "user <name>"
            r.execute_command("ACL", "SETUSER", svc, "reset", *rules)
            done.append(svc)
    finally:
        r.close()
    return done


def admin_url_from_env(env: dict[str, str]) -> str | None:
    """PS_REDIS_ADMIN_URL, or the host's own REDIS_URL when PS_REDIS_APPLY_ACL is on."""
    if env.get("PS_REDIS_ADMIN_URL"):
        return env["PS_REDIS_ADMIN_URL"]
    if env.get("PS_REDIS_APPLY_ACL", "").lower() in ("1", "true", "yes", "on") and env.get("REDIS_URL"):
        return env["REDIS_URL"]
    return None


def render_acl(env: dict[str, str] | None = None, prefix: str = "ps") -> str:
    env = dict(os.environ if env is None else env)
    lines = ["user default off resetkeys -@all"]
    admin_pw = env.get("PS_REDIS_PASSWORD_ADMIN")
    if admin_pw:
        lines.append(f"user admin on >{admin_pw} ~* &* +@all")
    for svc in SERVICES:
        pw = env.get(f"PS_REDIS_PASSWORD_{svc.upper()}")
        if not pw:
            raise SystemExit(f"missing PS_REDIS_PASSWORD_{svc.upper()}")
        lines.append(acl_line(svc, pw, prefix))
    return "\n".join(lines) + "\n"


if __name__ == "__main__":
    sys.stdout.write(render_acl())
