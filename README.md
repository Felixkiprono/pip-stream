# PipStream

Copy the Telegram trading signals you follow to your broker, automatically. Lives at **pips.stream**.

One repository, three parts:

| Part | Where | What it does |
|---|---|---|
| **Laravel 13** core | repo root (`app/`, `routes/`, `database/`) | accounts, roles, JSON API, Telegram linking, brokers, copy styles, billing (Paystack: M-Pesa and card), alerts, admin, kill switch |
| **React app** (installable PWA) | `web/`, built into `public/spa` | landing page, the customer app (mobile first, installs on the phone) and the admin console |
| **Python engine**, one service | `engine/` | `intake` (Telegram reader + rule-based parser), `risk` (rules, sizing, signed intents), `executor` (paper, MT5 bridge, Deriv), run as child processes in that order and restarted if one stops |

They talk over **Redis Streams** (each worker and Laravel has its own Redis user, so only `risk`
can create a trade) and share **one Postgres schema**, `database/schema/pipstream.sql`, applied by
`php artisan migrate`. Broker passwords and Telegram sessions are sealed (libsodium) to the one
engine worker that needs them; Laravel holds only the public keys.

Parsing is **rule-based only**. No AI model reads Telegram content.

> Channel names, house formats and sample posts in `engine/config/` are invented placeholders.

## Run it locally

```bash
./scripts/test-infra.sh                  # Postgres on :55432, Redis on :56379
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan pipstream:admin you@example.com --password=change-me
npm ci && npm run build                  # or `npm run dev` for the Vite dev server on :5173
php artisan serve                        # :8000
php artisan pipstream:consume            # engine events -> alerts, Telegram login answers
php artisan queue:work
pip install -e "engine[dev]" && pipstream keys    # put the keys in .env and your shell
PS_INGESTOR_FAKE_TELEGRAM=1 PS_INGESTOR_SOURCE=replay pipstream run engine
```

With `PS_INGESTOR_FAKE_TELEGRAM=1` the Telegram screens accept code **12345** (or 11111, then
password `demo`, to see the two-step screen). Never set it in production.

## Tests

```bash
php artisan test                         # Laravel
npm run typecheck                        # React
PS_TEST_DATABASE_URL=postgresql://postgres@127.0.0.1:55432/postgres pytest -q engine/tests
```

## Deploy with NanoDeploi

Site: *Use repository docker-compose.yml*, ingress service **nginx**, port **80**.

- Build commands: `php: composer install --no-dev --optimize-autoloader`, `node: npm ci && npm run build`
- Release commands: `php: php artisan migrate --force`, `php: php artisan optimize`, `php: php artisan queue:restart`
- Site-root `.env`: the output of `pipstream keys` (run it once in the engine container), plus
  `PS_APP_DIR=./src`, `PS_TELEGRAM_API_ID`, `PS_TELEGRAM_API_HASH` and `PS_METAAPI_TOKEN` (MT4/MT5 accounts
  through MetaApi; token at https://app.metaapi.cloud/token)
- `src/.env`: Laravel settings from `.env.example` (APP_KEY, Paystack, bot token, mail)

Prefer NanoDeploi's own services over the repository compose file? See `docs/deploy-nanodeploi.md`.

`docker-compose.yml` shows what runs where. Architecture notes are in `docs/architecture.md`.
