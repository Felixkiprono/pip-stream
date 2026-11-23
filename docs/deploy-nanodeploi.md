# Deploy on NanoDeploi, service by service (no repository docker-compose)

NanoDeploi 1.24+ can run every part of PipStream from its own settings. This is the same stack as
`docker-compose.yml`, mapped onto NanoDeploi's UI. Use one or the other, not both.

| PipStream part | NanoDeploi |
|---|---|
| nginx + php-fpm (Laravel + React) | the site's **PHP 8.4** runtime (PHP+Nginx combined), routed by Traefik |
| `events`, `queue`, `scheduler` | **Background processes** (each its own container from the PHP image) |
| React build | **Node** service + build command |
| engine | **Extra service** from the Dockerfile in `engine/`, hardened |
| Postgres 16 | **PostgreSQL 16-alpine** service |
| Redis with per-service users | **Redis 7** service; the engine creates the users (`PS_REDIS_APPLY_ACL=1`) |

## 1. Secrets (on your machine, once)

```bash
cd engine && pip install -e . && pipstream keys > ~/pipstream-prod.env    # keep private
php artisan key:generate --show                                           # APP_KEY
openssl rand -hex 24                                                      # Telegram webhook secret
```

`DB_PASSWORD` and `PS_REDIS_PASSWORD_ADMIN` from that file are not needed here: NanoDeploi
generates the database and Redis passwords itself.

## 2. Create the site

New site → **Laravel** → repository `Felixkiprono/pip-stream`, branch `main`, domain `pip.stream`.
Leave **Use repository docker-compose.yml** off.

**Services & versions**
- PHP **8.4**, PHP + Nginx combined. Document root `public`.
- Remove MySQL. Add **PostgreSQL 16-alpine**, **Redis 7-alpine**, **Node 22**.
- Redis → **Extra Redis arguments** (optional): `--appendonly yes`

**Build commands**
```
php: composer install --no-dev --optimize-autoloader
node: npm ci && npm run build
```

**Release commands**
```
php: php artisan migrate --force
php: php artisan optimize
php: php artisan queue:restart
```

**Background processes** (type Worker unless noted)
| Name | Command |
|---|---|
| events | `php artisan pipstream:consume` |
| queue | `php artisan queue:work --sleep=1 --tries=3 --max-time=3600` |
| scheduler (Scheduler) | `php artisan schedule:work` |

## 3. Extra service: engine

| Setting | Value |
|---|---|
| Service name | `engine` (Laravel calls `http://engine:8001`) |
| Runs from | **A Dockerfile in the repository** |
| Build context | `engine` |
| Dockerfile | `engine/Dockerfile` |
| Command | empty (the image runs `pipstream run engine`) |
| Share the site’s connection settings | **on** (gives it `DATABASE_URL` and `REDIS_URL`) |
| Route a domain to it | off |
| No new privileges | on |
| Read-only filesystem | on |
| Drop all Linux capabilities | on |
| Run as user | empty (the image runs as uid 10001) |
| Memory limit | `512m` (suggested) |

**Environment** (the engine's own; nothing else in the site can read these):
```
PS_ENV=production
PS_REDIS_HOST=redis
PS_REDIS_PORT=6379
PS_REDIS_APPLY_ACL=1
PS_REDIS_PASSWORD_INTAKE=…
PS_REDIS_PASSWORD_RISK=…
PS_REDIS_PASSWORD_EXECUTOR=…
PS_REDIS_PASSWORD_LARAVEL=…
PS_INTAKE_PRIVATE_KEY=…
PS_INTENT_SIGNING_KEY=…
PS_INTENT_VERIFY_KEY=…
PS_EXECUTOR_PRIVATE_KEY=…
PS_TELEGRAM_API_ID=…
PS_TELEGRAM_API_HASH=…
PS_METAAPI_TOKEN=…            (optional)
```

The engine uses `DATABASE_URL` when `PS_DATABASE_URL` is not set. With `PS_REDIS_APPLY_ACL=1` it
logs in with the site's `REDIS_URL` and creates the `intake`, `risk`, `executor` and `laravel`
users (the same rules as the ACL file) before it starts each worker. Each worker then gets only
its own Redis user and secrets. Run it by hand with `pipstream acl --apply`.

## 4. App environment (Edit .env → `src/.env`)

```
APP_NAME=PipStream
APP_ENV=production
APP_DEBUG=false
APP_KEY=…
APP_URL=https://pip.stream
PS_EA_URL=https://pip.stream
LOG_CHANNEL=stderr
SESSION_DRIVER=redis
SESSION_LIFETIME=43200
SESSION_SECURE_COOKIE=true
CACHE_STORE=redis
QUEUE_CONNECTION=redis
PS_ENGINE_URL=http://engine:8001
PS_REDIS_PASSWORD_LARAVEL=…      (same value as the engine's)
PS_INTAKE_PUBLIC_KEY=…
PS_EXECUTOR_PUBLIC_KEY=…
PS_TELEGRAM_BOT_TOKEN=…
PS_TELEGRAM_BOT_USERNAME=…
PS_TELEGRAM_BOT_WEBHOOK_SECRET=…
PAYSTACK_SECRET_KEY=…
MAIL_MAILER=…  (+ its settings)
MAIL_FROM_ADDRESS=alerts@pip.stream
PS_LIFETIME_EMAILS=you@example.com
```

Database and Redis host/password come from NanoDeploi. With `PS_REDIS_PASSWORD_LARAVEL` set,
Laravel logs in to Redis as `laravel` instead of the site's default user (`config/database.php`).
Never set `PS_INGESTOR_FAKE_TELEGRAM` in production.

## 5. Deploy, then once

```
php: php artisan pipstream:admin you@example.com --password=…
php: php artisan pipstream:lifetime
```

```bash
curl "https://api.telegram.org/bot<BOT_TOKEN>/setWebhook?url=https://pip.stream/webhooks/telegram/<WEBHOOK_SECRET>"
```
Paystack webhook: `https://pip.stream/webhooks/paystack`.

## 6. Check

```bash
curl -I https://pip.stream/up                                       # 200
curl -s https://pip.stream/ea/PipStream.mq5 | grep "PipStreamUrl ="  # https://pip.stream
```
Engine log: `redis users applied`, then the three `worker started` lines, then
`telethon source connected` once Telegram is linked in the app.

## Notes

- If Redis restarts on its own, its users are gone until the engine starts its next worker
  (it re-applies them before every start). Restart the engine service to heal at once.
- The engine's supervisor process holds the site's Redis password (to create the users); the
  workers don't get it.
- The engine has no published ports; Laravel reaches it on the site's private network.
