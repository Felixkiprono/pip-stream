"""The engine: ONE service (one container, one image) that runs PipStream's three workers as
its own child processes, started in pipeline order and restarted if one stops.

    executor  -> places orders, tracks positions        (started first: ready for intents)
    risk      -> checks rules, sizes, signs intents
    intake    -> reads Telegram, parses posts            (started last: work flows downstream)

Why child processes and not one big event loop: it stays one lightweight service to deploy and
watch, but each worker still gets ONLY its own secrets and its own Redis user, so a bug in the
Telegram reader can't reach the signing key or broker passwords. Each worker is a single
asyncio process (a few tens of MB of RAM); messages between them go over Redis Streams in
about a millisecond.

Environment (site-root .env, passed to the engine container):
    PS_DATABASE_URL, PS_REDIS_HOST, PS_REDIS_PORT,
    PS_REDIS_PASSWORD_INTAKE / _RISK / _EXECUTOR,
    PS_INTAKE_PRIVATE_KEY, PS_TELEGRAM_API_ID, PS_TELEGRAM_API_HASH      -> intake only
    PS_INTENT_SIGNING_KEY                                                -> risk only
    PS_INTENT_VERIFY_KEY, PS_EXECUTOR_PRIVATE_KEY, PS_MT5_*, PS_DERIV_*  -> executor only
    PS_ENGINE_WORKERS=executor,risk,intake   (optional: run a subset, e.g. scale intake alone)
    PS_REDIS_ADMIN_URL, or PS_REDIS_APPLY_ACL=1 with the host's REDIS_URL  (optional: Redis without
        an ACL file; the engine creates the worker and laravel users itself, see permissions.apply_acl)
    DATABASE_URL is used when PS_DATABASE_URL is not set.
"""
from __future__ import annotations

import asyncio
import json
import os
import signal
import sys
import time

ORDER = ("executor", "risk", "intake")

# Secrets and settings that belong to exactly one worker. Anything PS_* not listed here and
# not in SHARED is dropped for every child.
OWN: dict[str, tuple[str, ...]] = {
    "intake": ("PS_INTAKE_PRIVATE_KEY", "PS_TELEGRAM_API_ID", "PS_TELEGRAM_API_HASH", "PS_INGESTOR_"),
    "risk": ("PS_INTENT_SIGNING_KEY", "PS_RISK_"),
    "executor": ("PS_INTENT_VERIFY_KEY", "PS_EXECUTOR_PRIVATE_KEY", "PS_MT5_", "PS_DERIV_", "PS_ROUTER_"),
}
SHARED = ("PS_DATABASE_URL", "PS_ENV", "PS_LOG_LEVEL", "PS_STREAM_PREFIX", "PS_STREAM_MAXLEN",
          "PS_INTENT_KEY_ID", "PS_INTENT_TTL_MS", "PS_SYMBOLS_FILE", "PS_INTAKE_HTTP_")
PASSTHROUGH = ("PATH", "HOME", "LANG", "LC_ALL", "PYTHONPATH", "PYTHONUNBUFFERED", "PYTHONDONTWRITEBYTECODE", "TZ")


def child_env(worker: str, env: dict[str, str]) -> dict[str, str]:
    out = {k: v for k, v in env.items() if k in PASSTHROUGH}
    for k, v in env.items():
        if not k.startswith("PS_"):
            continue
        if any(k.startswith(p) for p in SHARED) or any(k.startswith(p) for p in OWN[worker]):
            out[k] = v
    out["PS_REDIS_URL"] = worker_redis_url(worker, env)
    return out


def worker_redis_url(worker: str, env: dict[str, str]) -> str:
    """Which Redis login a worker uses, most specific first:
    1. its own ACL user (PS_REDIS_PASSWORD_<WORKER>) on PS_REDIS_HOST, or on the host's Redis
    2. an explicit PS_REDIS_URL
    3. the hosting platform's login (REDIS_URL, or REDIS_HOST + REDIS_PASSWORD)
    4. an unauthenticated Redis on PS_REDIS_HOST (local dev)"""
    from urllib.parse import urlsplit
    from ..common.permissions import host_redis_url
    hosted = host_redis_url(env)
    pw = env.get(f"PS_REDIS_PASSWORD_{worker.upper()}", "")
    if pw and (env.get("PS_REDIS_HOST") or hosted):
        h = urlsplit(hosted) if hosted and not env.get("PS_REDIS_HOST") else None
        host = env.get("PS_REDIS_HOST") or (h.hostname if h else "redis")
        port = env.get("PS_REDIS_PORT") or (str(h.port) if h and h.port else "6379")
        return f"redis://{worker}:{pw}@{host}:{port}/0"
    if "://" in env.get("PS_REDIS_URL", ""):
        return env["PS_REDIS_URL"]
    if hosted:
        return hosted
    return f"redis://{env.get('PS_REDIS_HOST', 'redis')}:{env.get('PS_REDIS_PORT', '6379')}/0"


def log(msg: str, **kw) -> None:
    print(json.dumps({"ts": time.strftime("%Y-%m-%dT%H:%M:%S"), "level": "INFO", "service": "engine",
                      "msg": msg, **kw}), flush=True)


class Supervisor:
    def __init__(self, workers: tuple[str, ...], env: dict[str, str]):
        self.workers, self.env = workers, env
        self.procs: dict[str, asyncio.subprocess.Process] = {}
        self.stopping = False

    def ensure_acl(self) -> None:
        """Hosts without an ACL file (NanoDeploi's Redis): (re)create the workers' Redis users
        before a worker starts, so a Redis restart heals with the next worker restart."""
        from ..common.permissions import admin_url_from_env, apply_acl
        url = admin_url_from_env(self.env)
        if not url:
            return
        try:
            users = apply_acl(url, self.env, self.env.get("PS_STREAM_PREFIX", "ps"))
            log("redis users applied", users=users)
        except Exception as e:  # keep going: the worker fails to log in and is retried with backoff
            log("redis users not applied", error=f"{type(e).__name__}: {e}")

    async def spawn(self, w: str) -> None:
        await asyncio.to_thread(self.ensure_acl)
        self.procs[w] = await asyncio.create_subprocess_exec(
            sys.executable, "-m", f"pipstream.apps.{w}", env=child_env(w, self.env))
        log("worker started", worker=w, pid=self.procs[w].pid)

    async def watch(self, w: str) -> None:
        backoff = 1.0
        while not self.stopping:
            started = time.monotonic()
            code = await self.procs[w].wait()
            if self.stopping:
                return
            backoff = 1.0 if time.monotonic() - started > 60 else min(backoff * 2, 30)
            log("worker exited; restarting", worker=w, code=code, in_s=backoff)
            await asyncio.sleep(backoff)
            await self.spawn(w)

    async def run(self) -> int:
        loop = asyncio.get_running_loop()
        stop = asyncio.Event()
        for sig in (signal.SIGINT, signal.SIGTERM):
            loop.add_signal_handler(sig, stop.set)
        for w in self.workers:  # pipeline order, with a moment for each to connect
            await self.spawn(w)
            await asyncio.sleep(0.5)
        watchers = [asyncio.create_task(self.watch(w)) for w in self.workers]
        await stop.wait()
        self.stopping = True
        log("stopping workers")
        for w in reversed(self.workers):  # stop intake first so nothing new enters
            p = self.procs.get(w)
            if p and p.returncode is None:
                p.send_signal(signal.SIGTERM)
                try:
                    await asyncio.wait_for(p.wait(), 20)
                except asyncio.TimeoutError:
                    p.kill()
        for t in watchers:
            t.cancel()
        return 0


def main() -> None:
    env = dict(os.environ)
    # Hosts that hand out one DATABASE_URL (NanoDeploi, Heroku-style) instead of PS_DATABASE_URL.
    if not env.get("PS_DATABASE_URL") and env.get("DATABASE_URL"):
        env["PS_DATABASE_URL"] = env["DATABASE_URL"]
    wanted = tuple(w.strip() for w in env.get("PS_ENGINE_WORKERS", ",".join(ORDER)).split(",") if w.strip())
    workers = tuple(w for w in ORDER if w in wanted)
    if not workers:
        raise SystemExit("PS_ENGINE_WORKERS selects no known worker")
    sys.exit(asyncio.run(Supervisor(workers, env).run()))


if __name__ == "__main__":
    main()
