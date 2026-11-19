#!/usr/bin/env bash
# Start throwaway Postgres + Redis for tests without Docker (CI uses service containers).
#   source scripts/test-infra.sh   -> exports PS_TEST_DATABASE_URL / PS_TEST_REDIS_PORT
set -euo pipefail
DIR="${PS_TEST_INFRA_DIR:-/tmp/pipstream-test-infra}"
PGBIN="${PGBIN:-$(ls -d /usr/lib/postgresql/*/bin 2>/dev/null | tail -1)}"
mkdir -p "$DIR"
if [ ! -f "$DIR/pg/PG_VERSION" ]; then
  mkdir -p "$DIR/pg"; chown postgres "$DIR" "$DIR/pg" 2>/dev/null || true
  su postgres -c "$PGBIN/initdb -D $DIR/pg -U postgres -A trust" >/dev/null
fi
su postgres -c "$PGBIN/pg_ctl -D $DIR/pg -o '-p 55432 -k $DIR' -l $DIR/pg.log start" >/dev/null || true
redis-server --port 56379 --daemonize yes --save '' --appendonly no >/dev/null
sleep 1
export PS_TEST_DATABASE_URL="postgresql://postgres@127.0.0.1:55432/postgres"
export PS_TEST_REDIS_PORT=56379
echo "postgres: $PS_TEST_DATABASE_URL  redis port: $PS_TEST_REDIS_PORT"
