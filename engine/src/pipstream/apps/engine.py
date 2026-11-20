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
    if "PS_REDIS_URL" in env and not env.get("PS_REDIS_HOST"):
        out["PS_REDIS_URL"] = env["PS_REDIS_URL"]  # dev: one unauthenticated Redis
    else:
        host, port = env.get("PS_REDIS_HOST", "redis"), env.get("PS_REDIS_PORT", "6379")
        pw = env.get(f"PS_REDIS_PASSWORD_{worker.upper()}", "")
        out["PS_REDIS_URL"] = f"redis://{worker}:{pw}@{host}:{port}/0" if pw else f"redis://{host}:{port}/0"
    return out


def log(msg: str, **kw) -> None:
    print(json.dumps({"ts": time.strftime("%Y-%m-%dT%H:%M:%S"), "level": "INFO", "service": "engine",
                      "msg": msg, **kw}), flush=True)


class Supervisor:
    def __init__(self, workers: tuple[str, ...], env: dict[str, str]):
        self.workers, self.env = workers, env
        self.procs: dict[str, asyncio.subprocess.Process] = {}
        self.stopping = False

    async def spawn(self, w: str) -> None:
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
    wanted = tuple(w.strip() for w in env.get("PS_ENGINE_WORKERS", ",".join(ORDER)).split(",") if w.strip())
    workers = tuple(w for w in ORDER if w in wanted)
    if not workers:
        raise SystemExit("PS_ENGINE_WORKERS selects no known worker")
    sys.exit(asyncio.run(Supervisor(workers, env).run()))


if __name__ == "__main__":
    main()
