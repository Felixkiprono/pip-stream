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
    "ingestor": ServicePerms(
        publish=frozenset({T.TG_MESSAGE_NEW, T.TG_MESSAGE_EDITED}),
        broadcast=frozenset({T.CONFIG_CHANGED}),
        rw_keys=("ps:dedupe:*", "ps:lease:*", "ps:editver:*"),
    ),
    "parser": ServicePerms(
        publish=frozenset({T.SIGNAL_PARSED, T.SIGNAL_REJECTED}),
        consume=frozenset({T.TG_MESSAGE_NEW, T.TG_MESSAGE_EDITED}),
        broadcast=frozenset({T.CONFIG_CHANGED}),
    ),
    "risk_engine": ServicePerms(
        publish=frozenset({T.TRADE_INTENT, T.RISK_LIMIT_HIT}),
        consume=frozenset({T.SIGNAL_PARSED}),
        broadcast=frozenset({T.CONFIG_CHANGED, T.BILLING_CHANGED}),
        ro_keys=("ps:acct:*", "ps:quote:*", "ps:killswitch:*"),
    ),
    "execution_router": ServicePerms(
        publish=frozenset({T.TRADE_EXECUTED, T.TRADE_FAILED, T.ACCOUNT_SNAPSHOT}),
        consume=frozenset({T.TRADE_INTENT}),
        broadcast=frozenset({T.CONFIG_CHANGED}),
        rw_keys=("ps:idem:*", "ps:acct:*", "ps:quote:*", "ps:paper:*"),
        ro_keys=("ps:killswitch:*",),
    ),
    "position_tracker": ServicePerms(
        publish=frozenset({T.TRADE_CLOSED}),
        consume=frozenset({T.TRADE_EXECUTED, T.ACCOUNT_SNAPSHOT}),
    ),
    "notifications": ServicePerms(
        publish=frozenset(),
        consume=frozenset({T.TRADE_EXECUTED, T.TRADE_FAILED, T.TRADE_CLOSED,
                           T.RISK_LIMIT_HIT, T.SIGNAL_REJECTED}),
    ),
    "billing": ServicePerms(
        publish=frozenset({T.BILLING_CHANGED}),
        consume=frozenset({T.BILLING_WEBHOOK}),
    ),
    "core_api": ServicePerms(
        publish=frozenset({T.CONFIG_CHANGED, T.BILLING_WEBHOOK}),
        rw_keys=("ps:killswitch:*",),
        ro_keys=("ps:health:*", "ps:acct:*"),
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
    own = [f"~{prefix}:health:{service}", f"~{prefix}:deliveries:{service}", *[f"~{k}" for k in p.rw_keys]]
    own += [f"%R~{k}" for k in p.ro_keys]
    parts.append(f"({_KV_CMDS} {' '.join(own)})")
    return " ".join(parts)


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
