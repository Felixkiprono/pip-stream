# PipStream

Telegram signal copier, built as event-driven services. This repo is the **low-cost
experiment** from the project handoff (§9): one VPS, Docker Compose, Postgres + Redis
Streams, rule-based parsing, paper / MT5-bridge / Deriv-demo execution.

- How the services fit together: **[docs/architecture.md](docs/architecture.md)**
- Channel / account / rule setup (no web app yet): [config/pipstream.yaml](config/pipstream.yaml)

> Channel ids, house formats and the sample posts in `config/replay/` are **placeholders**.
> Replace them with real samples from the target channels before judging parse accuracy.

## Services

| Service | Package | Consumes | Publishes | Network |
|---|---|---|---|---|
| Core API | `services/core_api` | (HTTP) | `config.changed`, `billing.webhook` | public (127.0.0.1 → reverse proxy) |
| Telegram Ingestor | `services/ingestor` | `config.changed`* | `tg.message.new`, `tg.message.edited` | outbound only |
| Signal Parser | `services/parser` | `tg.message.*` | `signal.parsed`, `signal.rejected` | internal |
| Rules / Risk Engine | `services/risk_engine` | `signal.parsed` | `trade.intent` (signed), `risk.limit_hit` | internal |
| Execution Router | `services/execution_router` | `trade.intent` | `trade.executed`, `trade.failed`, `account.snapshot` | outbound to brokers |
| Position Tracker | `services/position_tracker` | `trade.executed`, `account.snapshot` | `trade.closed` | internal |
| Notifications | `services/notifications` | `trade.*`, `risk.limit_hit`, `signal.rejected` | – | outbound to providers |
| Billing | `services/billing` | `billing.webhook` | `billing.changed` | internal |

\* broadcast topic: every replica receives it.

Connectors (execution plugins): `paper`, `mt5_bridge`, `deriv_options` in
`services/execution_router/connectors/`. Add one by implementing the `Connector` protocol
and decorating it with `@register("name")`.

## Run it

```bash
cp .env.example .env
docker compose build
docker compose run --rm keys >> .env        # signing keys, master key, per-service Redis passwords
docker compose up -d                        # replay mode: plays config/replay/sample_posts.jsonl
docker compose logs -f parser risk-engine execution-router
```

Check what happened to a post (every event carries a `trace_id`):

```bash
TOKEN=$(grep ^PS_ADMIN_TOKEN .env | cut -d= -f2)
curl -s -H "Authorization: Bearer $TOKEN" localhost:8080/v1/admin/status
curl -s -H "Authorization: Bearer $TOKEN" "localhost:8080/v1/admin/signals?limit=20"
curl -s -H "Authorization: Bearer $TOKEN" localhost:8080/v1/admin/trace/<trace_id>
# try a parser profile against a post without saving it
curl -s -H "Authorization: Bearer $TOKEN" -H 'content-type: application/json' \
     -d '{"text":"GOLD BUY 2348-2350 SL 2340 TP 2360"}' localhost:8080/v1/admin/parse-preview
# kill switch
curl -s -H "Authorization: Bearer $TOKEN" -H 'content-type: application/json' -d '{"on":true}' \
     localhost:8080/v1/admin/kill-switch
```

### Going live on Telegram (spare account)

1. Get `api_id` / `api_hash` at my.telegram.org, put them in `.env`.
2. Link the account (prompts for the login code; the session is stored encrypted):
   `docker compose run --rm -it ingestor python -m pipstream.services.ingestor.login --account-id tga_spare --user-id usr_owner --phone +254... --list-channels`
3. Put the printed channel ids into `config/pipstream.yaml`, set `PS_INGESTOR_SOURCE=telethon`,
   then `docker compose up -d migrate ingestor`.

### Experiment plan mapping (handoff §9)

- **Week 1, shadow:** `mode: shadow` rules record "would have traded" in `shadow_trades`.
- **Week 2, demo execution:** enable `rule_gold_mt5` (MT5 bridge, compose profile `mt5`) and
  `rule_synth_deriv` (Deriv options demo) after filling `PS_MT5_*` / `PS_DERIV_*`.
- **Weeks 3–4, measure:** `signals` (parse/reject rate per channel), `orders.latency_ms`
  (post → broker ack), `orders.error`, `positions` (follow-ups applied).

## Tests

```bash
pip install -e ".[dev]"
source scripts/test-infra.sh   # throwaway Postgres + redis-server, no Docker needed
pytest -q
```

The end-to-end test replays posts through all services, each on its own Redis ACL user, and
checks sizing, multi-TP split, "move SL to entry", partial close, filters, shadow mode,
dedupe, and that forged or replayed intents are refused.

## Deploy

The compose file is the deploy unit (works with NanoDeploi or plain `docker compose` on the
VPS). Only `core-api` publishes a port, bound to 127.0.0.1; front it with a TLS reverse proxy.
