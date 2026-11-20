#!/usr/bin/env bash
# Run all of PipStream on this machine: Laravel (:8000), its event consumer and queue worker,
# and the Python engine (intake, risk, executor) reading your real Telegram.
#
#   ./scripts/dev.sh          first run sets everything up; Ctrl-C stops it all
#
# Needs: PHP 8.3+ with pdo_pgsql + redis (Laravel Herd has both), Composer, Node 20+,
# Python 3.12+, and Postgres + Redis (Docker is used for them if it is installed).
set -euo pipefail
cd "$(dirname "$0")/.."

say() { printf '\033[1;34m[pipstream]\033[0m %s\n' "$*"; }

# ---------------------------------------------------------------- Postgres + Redis
if ! (exec 3<>/dev/tcp/127.0.0.1/55432) 2>/dev/null; then
  if command -v docker >/dev/null; then
    say "starting Postgres (55432) and Redis (56379) in Docker"
    docker compose -f docker-compose.dev.yml up -d
    for _ in $(seq 1 30); do (exec 3<>/dev/tcp/127.0.0.1/55432) 2>/dev/null && break; sleep 1; done
  else
    say "Postgres is not on 127.0.0.1:55432 and Docker is not installed. Start Postgres + Redis there, then rerun."
    exit 1
  fi
fi

# ---------------------------------------------------------------- Python engine
if [ ! -x .venv/bin/pipstream ]; then
  say "creating .venv and installing the engine (with Telethon)"
  python3 -m venv .venv
  .venv/bin/pip install -q --upgrade pip
  .venv/bin/pip install -q -e "engine[telegram]"
fi
export PATH="$PWD/.venv/bin:$PATH"

# ---------------------------------------------------------------- .env (one file for both)
if [ ! -f .env ]; then
  say "writing .env from .env.local.example with fresh keys"
  cp .env.local.example .env
  pipstream keys | grep -E '^PS_(INTENT|INTAKE|EXECUTOR)_' >> .env
fi
fake_override=${PS_INGESTOR_FAKE_TELEGRAM:-}   # PS_INGESTOR_FAKE_TELEGRAM=true ./scripts/dev.sh wins over .env
set -a; . ./.env; set +a
[ -n "$fake_override" ] && export PS_INGESTOR_FAKE_TELEGRAM=$fake_override
if [ "${PS_INGESTOR_FAKE_TELEGRAM:-false}" = "true" ]; then
  say "stand-in Telegram: connect with any phone + code 12345, follow a channel, then e.g."
  say "  .venv/bin/pipstream fake-post -1001000000001 'XAUUSD BUY 2348-2350 SL 2340 TP 2360'"
  say "  .venv/bin/pipstream fake-saved     # the 'PipStream read a signal' notes"
elif [ -z "${PS_TELEGRAM_API_ID:-}" ] || [ -z "${PS_TELEGRAM_API_HASH:-}" ]; then
  say "Add PS_TELEGRAM_API_ID and PS_TELEGRAM_API_HASH to .env (from https://my.telegram.org), then rerun."
  say "(or try it first with the stand-in: PS_INGESTOR_FAKE_TELEGRAM=true ./scripts/dev.sh)"
  exit 1
fi

# ---------------------------------------------------------------- Laravel + React
[ -d vendor ] || composer install
grep -q '^APP_KEY=base64' .env || php artisan key:generate
[ -d node_modules ] || npm ci
[ -f public/spa/index.html ] || npm run build
php artisan migrate --force

# ---------------------------------------------------------------- run everything
pids=()
# each process: tagged lines here AND its own file, storage/logs/<name>.log (tail -f to watch one)
mkdir -p storage/logs
run() { local name=$1; shift; ( "$@" 2>&1 | tee -a "storage/logs/$name.log" | sed -u "s/^/[$name] /" ) & pids+=($!); }
trap 'say "stopping"; kill ${pids[@]} 2>/dev/null; wait; exit 0' INT TERM

# several PHP workers: a Telegram login step waits for the engine without blocking other requests
run web     env PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload --host=127.0.0.1 --port=8000
run events  php artisan pipstream:consume
run queue   php artisan queue:work --sleep=1
run engine  pipstream run engine

say "logs: storage/logs/{web,events,queue,engine}.log   e.g. tail -f storage/logs/engine.log"
say "open http://127.0.0.1:8000  (create an admin: php artisan pipstream:admin you@example.com --password=...)"
wait
