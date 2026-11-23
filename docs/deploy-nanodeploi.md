# Deploy PipStream on NanoDeploi, field by field (no repository docker-compose)

Every field below is copied from NanoDeploi 1.24's **Create site** wizard and site page
(`CreateSitePage.tsx`, `ExtraServiceFields.tsx`, `ProcessSwitches.tsx`, `SiteComponents.tsx`,
`config/deploy.php`). For each one: the options NanoDeploi offers, and what to pick for PipStream.

This runs the same stack as `docker-compose.yml`. Use one or the other, not both.

| PipStream part | Where it goes in NanoDeploi |
|---|---|
| nginx + php-fpm (Laravel + React) | Runtime **PHP**, ingress **Via Nginx** |
| `events` | site page → Processes → **Add process** |
| queue worker, scheduler | wizard step **Processes** (Queue worker, Scheduler) |
| React build | **Node.js** service + build command |
| engine | **Extra service** from `engine/Dockerfile`, hardened |
| Postgres 16 | **PostgreSQL** service, `16-alpine` |
| Redis + per-service users | **Redis** service; the engine creates the users (`PS_REDIS_APPLY_ACL=1`) |

## 0. Secrets, on your machine

```bash
cd ~/2027/Forex/pipstream
.venv/bin/pipstream keys > ~/pipstream-prod.env    # engine keys + Redis user passwords
php artisan key:generate --show                    # APP_KEY
openssl rand -hex 24                               # Telegram webhook secret
```
Keep them private. `DB_PASSWORD` and `PS_REDIS_PASSWORD_ADMIN` from that file aren't used here:
NanoDeploi generates the database and Redis passwords.

---

## Wizard step 1 · Infrastructure

| Field | Options | Pick |
|---|---|---|
| **Server** | your servers; any not connected show "(Pending)" or their status | the server for pip.stream (must show no status suffix = Connected) |

## Wizard step 2 · Repository

| Field | Options | Pick |
|---|---|---|
| **Repository type** | `Private (browse via GitHub)` · `Public (paste repo URL)` | **Private (browse via GitHub)** |
| **GitHub account** | your connected accounts (`· default` marks the default) | the account that can read Felixkiprono/pip-stream |
| **Repository** | repositories of that account | **Felixkiprono/pip-stream** |
| **Branch** | the repository's branches | **main** |
| Detected defaults panel → *Overwrite runtime/build with detected defaults* | button | don't press it; the steps below set everything |
| **Use repository docker-compose.yml** | toggle | **off** (turning it on skips the Runtime, Services and Processes steps) |

## Wizard step 3 · App basics

| Field | Options | Pick |
|---|---|---|
| **What are you deploying?** | `Laravel` · `PHP` · `WordPress` · `Node.js` · `Next.js / Nuxt` · `React / Vue / Vite` · `Plain HTML` · `Django` · `FastAPI / Flask` · `Rust` · `Generic (mix and match)` | **Laravel** (fills PHP 8.4, MySQL 8.0, Redis 7-alpine, Via Nginx, doc root `public`, Laravel build/release; we change several below) |
| **App name** | text | `PipStream` |
| **Domain** | text | `pip.stream` |
| **Doc root** | text (`public, dist, build`) | `public` (keep the Laravel default) |

## Wizard step 4 · Runtime

| Field | Options | Pick |
|---|---|---|
| **Runtime** | `PHP (Laravel/Symfony)` · `Node (Express/Nest/Next)` · `Python (FastAPI/Django/Flask)` · `Rust (cargo)` · `Static (Vite/React)` | **PHP (Laravel/Symfony)** (Laravel default) |
| **Mode** | `Static (Nginx)` · `Runtime (port)` | leave empty (**Select…**): PHP doesn't use it |
| **Ingress** | `Default` · `Traefik → app` · `Via Nginx` | **Via Nginx** (Laravel default) |

No Start command or Port for PHP.

## Wizard step 5 · Runtime versions

| Field | Options | Pick |
|---|---|---|
| **PHP version** | `Default` · `8.2` · `8.3` · `8.4` · `8.5` | **8.4** (Laravel 13 needs 8.3+; the Laravel preset already uses 8.4) |

## Wizard step 6 · Services

Each row has **Service**, **Version / image**, **Order** and a remove button. Service options:
`PHP (App)`, `Nginx (Proxy)`, `Node.js (App)`, `MySQL (Data)`, `PostgreSQL (Data)`, `Redis (Data)`,
`WordPress (App)`, `Python (App)`, `Rust (App)`, `Temporal (Data)`, `Temporal UI (Data)`,
`MongoDB (Data)`, `RabbitMQ (Data)`, `Kafka (Data)`, `Elasticsearch (Data)`, `Grafana (Data)`.
Every version list also has **Other (enter manually)**.

The Laravel preset gives PHP 8.4, MySQL 8.0 and Redis 7-alpine. Make the rows:

| Service | Version / image options | Pick | Order |
|---|---|---|---|
| **PHP** | `8.2` `8.3` `8.4` `8.5` | **8.4** (keep) | 0 |
| ~~MySQL~~ | `8.0` `8.4` `9.0` | **remove this row** (trash icon) | |
| **PostgreSQL** (Add service) | `17-alpine` `16` `16-alpine` `15-alpine` | **16-alpine** | 1 |
| **Redis** | `7-alpine` `7` | **7-alpine** (keep) | 2 |
| **Node.js** (Add service) | `12` `12-alpine` `20` `20-alpine` `22` `22-alpine` `lts` `lts-alpine` `latest` | **22-alpine** | 3 |

On the Redis row, **Extra Redis arguments** (redis-server flags, max 1000 characters,
`--requirepass` is ignored): `--appendonly yes`. Optional but recommended, so streams survive a
Redis restart. Don't put the ACL here: it's about 4000 characters and NanoDeploi can't mount a
file. The engine creates the users instead.

### Extra services → **Add extra service**

| Field | Options | Pick |
|---|---|---|
| **Service name** | text | `engine` (Laravel calls `http://engine:8001`) |
| **Runs from** | `The app container (same image and code)` · `Its own Docker image` · `A Dockerfile in the repository` | **A Dockerfile in the repository** |
| **Dockerfile** | path in the repository | `engine/Dockerfile` |
| **Build context** | folder in the repository (empty = root) | `engine` |
| **Command** | text (empty = the image's own command) | leave **empty**: the image runs `pipstream run engine` |
| **Environment** | one `KEY=value` per line | the block below |
| **Share the site's connection settings** | toggle (default on) | **on**: gives the engine `DATABASE_URL` and `REDIS_URL` |
| **Route a domain to it (HTTPS through Traefik)** | toggle (default off); when on: **Hostname**, **Container port** (default 8080) | **off**: only Laravel talks to the engine |
| **No new privileges** | toggle (default on) | **on** (keep) |
| **Read-only filesystem** | toggle (default off; /tmp stays writable) | **on** |
| **Drop all Linux capabilities** | toggle (default off) | **on** |
| **Run as user** | text, e.g. `1000:1000` | leave **empty**: the image already runs as uid 10001 |
| **Memory limit** | text, e.g. `512m`, `1g` | `512m` |
| **CPU limit** | text, e.g. `0.5` | empty (or `1`) |
| **Process limit** | number, e.g. `200` | `200` |

**Environment** for the engine (values from `~/pipstream-prod.env` and my.telegram.org). Nothing
else in the site can read these:
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
PS_METAAPI_TOKEN=…            (only for hosted MetaTrader)
```
The engine uses `DATABASE_URL` when `PS_DATABASE_URL` is not set. With `PS_REDIS_APPLY_ACL=1` it
logs in with the site's `REDIS_URL` and creates the `intake`, `risk`, `executor` and `laravel`
Redis users (the same rules as the ACL file) before it starts each worker.

## Wizard step 7 · Build

The two boxes are prefilled by the Laravel preset. Prefixes allowed: `php:`, `node:`, `wordpress:`.

| Field | Preset (Laravel) | Replace with |
|---|---|---|
| **Build** | `php: composer install --no-dev --optimize-autoloader` | see below (adds the React build) |
| **Release** | `php: php artisan migrate --force`, `php: php artisan config:cache`, `php: php artisan route:cache`, `php: php artisan view:cache` | see below |

Build:
```
php: composer install --no-dev --optimize-autoloader
node: npm ci && npm run build
```
Release:
```
php: php artisan migrate --force
php: php artisan optimize
php: php artisan queue:restart
```

## Wizard step 8 · Processes

| Field | Options | Pick |
|---|---|---|
| **Queue worker** | toggle; **Command** (Laravel preset: `php artisan queue:work --tries=3 --timeout=60 --sleep=3 --max-time=3600`), **Replicas** | **on**; Command `php artisan queue:work --sleep=1 --tries=3 --max-time=3600`; Replicas `1` |
| **Scheduler** | toggle; **Command** (preset: `php artisan schedule:work`); **Schedule**: "Runs all the time and starts your scheduled tasks itself." | **on**; keep `php artisan schedule:work` |
| **Advanced: run workers from my own Supervisor config** | toggle | **off** |

The `events` consumer can't be added here; add it on the site page (step 11b).

## Wizard step 9 · Review

Check: Server, App `PipStream · pip.stream`, Repository `Felixkiprono/pip-stream @ main`,
Runtime PHP, 2 build command(s) · 3 release command(s), Processes (queue worker, scheduler),
Extra services `engine (A Dockerfile in the repository)`. Then **Create site**.

The first deploy may fail until the app .env below is saved; that's expected.

---

## 10. Site page → **Edit .env**

The dialog shows NanoDeploi's settings (DB_*, REDIS_*) plus yours. Add below them:
```
APP_NAME=PipStream
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:…
APP_URL=https://pip.stream
PS_EA_URL=https://pip.stream
LOG_CHANNEL=stderr
SESSION_DRIVER=redis
SESSION_LIFETIME=43200
SESSION_SECURE_COOKIE=true
CACHE_STORE=redis
QUEUE_CONNECTION=redis
PS_ENGINE_URL=http://engine:8001
PS_REDIS_PASSWORD_LARAVEL=…      (same value as in the engine)
PS_INTAKE_PUBLIC_KEY=…
PS_EXECUTOR_PUBLIC_KEY=…
PS_TELEGRAM_BOT_TOKEN=…
PS_TELEGRAM_BOT_USERNAME=…
PS_TELEGRAM_BOT_WEBHOOK_SECRET=…
PAYSTACK_SECRET_KEY=sk_live_…
MAIL_MAILER=…  (+ its settings)
MAIL_FROM_ADDRESS=alerts@pip.stream
PS_LIFETIME_EMAILS=you@example.com
```
Don't add `DB_PASSWORD`, `REDIS_PASSWORD` or `PS_INGESTOR_FAKE_TELEGRAM`. With
`PS_REDIS_PASSWORD_LARAVEL` set, Laravel logs in to Redis as `laravel` instead of the site's
default user (`config/database.php`). **Save**.

## 11. Site page → Services tab

**a) Processes (worker / cron) → Add process**

| Field | Options | Pick |
|---|---|---|
| **Name** | text | `events` |
| **Type** | `Worker` · `Cron` | **Worker** |
| **Runtime (base service)** | `PHP` · `Node` · `Python` | **PHP** |
| **Restart policy** | `Always` · `On failure` · `Never` | **Always** |
| **Command** | text (runs in the app folder as the app user, with the app's environment) | `php artisan pipstream:consume` |

**b) Redis row → Extra Redis arguments:** `--appendonly yes`, if you didn't set it in the wizard.

Then **Deploy**.

## 12. After the first successful deploy

Configuration → **Run command** (team owner only):
```
php: php artisan pipstream:admin you@example.com --password=…
php: php artisan pipstream:lifetime
```
Telegram bot webhook (on your Mac):
```bash
curl "https://api.telegram.org/bot<BOT_TOKEN>/setWebhook?url=https://pip.stream/webhooks/telegram/<WEBHOOK_SECRET>"
```
Paystack dashboard → API Keys & Webhooks → `https://pip.stream/webhooks/paystack`.

## 13. Check

```bash
curl -I https://pip.stream/up                                       # 200
curl -s https://pip.stream/ea/PipStream.mq5 | grep "PipStreamUrl ="  # https://pip.stream
```
Containers: php, pgsql, redis, node, the queue worker, the scheduler, events and engine.
Engine log: `redis users applied`, then three `worker started`, then `telethon source connected`
once you link Telegram in the app. Stop the local Mac stack first so the two don't read the same
Telegram account.

## What doesn't fit NanoDeploi as-is

- **Redis ACL file:** Extra Redis arguments are capped at 1000 characters and no file can be
  mounted. Handled in PipStream: the engine creates the users (`PS_REDIS_APPLY_ACL=1`,
  `pipstream acl --apply`). If Redis restarts on its own, the users come back with the engine's
  next worker start; restart the engine service to heal at once.
- **`events` worker:** the wizard's Processes step only has Queue worker and Scheduler; add it on
  the site page (11a).
- **Release commands** only run in `php:`, `node:` or `wordpress:` containers, so nothing in the
  release can run inside the engine. Nothing needs to.
- **Admin password in the engine:** its supervisor process holds the site's Redis password (to
  create the users); the workers don't get it.
