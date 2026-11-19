-- PipStream core schema. Table ownership (who writes) is noted per table; other services
-- may read. Secrets are stored only as ciphertext (see pipstream.common.vault).

-- ---------------------------------------------------------------- identity (core_api)
create table users (
  id            text primary key,
  email         text unique not null,
  display_name  text,
  status        text not null default 'active' check (status in ('active','suspended')),
  kill_switch   boolean not null default false,
  created_at    timestamptz not null default now()
);

create table telegram_accounts (                     -- core_api / ingestor login flow
  id                  text primary key,
  user_id             text not null references users(id),
  phone_hint          text,                          -- last digits only
  session_ciphertext  text,                          -- encrypted Telethon StringSession
  status              text not null default 'active' check (status in ('active','revoked','error')),
  created_at          timestamptz not null default now()
);

-- ---------------------------------------------------------------- channels & parsing
create table parser_profiles (                       -- core_api (parser studio)
  id              text primary key,
  name            text not null,
  version         int  not null default 1,
  min_confidence  double precision not null default 0.8,
  config          jsonb not null default '{}',       -- aliases, words, templates, flags
  updated_at      timestamptz not null default now()
);

create table channels (                              -- core_api
  id                 text primary key,
  tg_chat_id         bigint unique not null,
  title              text,
  username           text,
  parser_profile_id  text references parser_profiles(id),
  active             boolean not null default true,
  created_at         timestamptz not null default now()
);

create table channel_readers (                       -- core_api; read once, fan out
  channel_id           text not null references channels(id),
  telegram_account_id  text not null references telegram_accounts(id),
  role                 text not null default 'active' check (role in ('active','standby')),
  primary key (channel_id, telegram_account_id)
);

create table subscriptions (                         -- user follows channel
  user_id     text not null references users(id),
  channel_id  text not null references channels(id),
  created_at  timestamptz not null default now(),
  primary key (user_id, channel_id)
);

create table signals (                               -- parser
  id            text primary key,
  channel_id    text not null references channels(id),
  message_id    bigint not null,
  reply_to_id   bigint,
  edit_version  int not null default 0,
  status        text not null check (status in ('parsed','rejected')),
  kind          text,
  symbol        text,
  side          text,
  confidence    double precision,
  reason        text,
  payload       jsonb not null,
  trace_id      text,
  created_at    timestamptz not null default now(),
  unique (channel_id, message_id, edit_version)
);
create index signals_channel_msg on signals(channel_id, message_id);

-- ---------------------------------------------------------------- brokers & rules
create table connectors (                            -- registry mirror of code plugins
  name     text primary key,
  kind     text not null,                            -- cfd | options | paper | webhook
  enabled  boolean not null default true,
  config   jsonb not null default '{}'
);

create table broker_accounts (                       -- core_api
  id                      text primary key,
  user_id                 text not null references users(id),
  connector               text not null references connectors(name),
  label                   text,
  credentials_ciphertext  text,
  symbol_map              jsonb not null default '{}',   -- canonical -> broker symbol
  enabled                 boolean not null default true,
  created_at              timestamptz not null default now()
);

create table copy_rules (                            -- core_api
  id                 text primary key,
  user_id            text not null references users(id),
  channel_id         text not null references channels(id),
  broker_account_id  text not null references broker_accounts(id),
  enabled            boolean not null default true,
  mode               text not null default 'shadow' check (mode in ('shadow','paper','live')),
  sizing_mode        text not null default 'fixed' check (sizing_mode in ('fixed','risk_pct','multiplier','stake')),
  fixed_lots         double precision,
  risk_pct           double precision,
  multiplier         double precision,
  stake              double precision,
  max_lot            double precision not null default 1.0,
  tp_split           text not null default 'equal' check (tp_split in ('equal','first_only')),
  filters            jsonb not null default '{}',   -- symbols_allow/deny, hours_utc, days
  limits             jsonb not null default '{}',   -- max_open_trades, max_daily_loss, max_entry_slippage_points
  created_at         timestamptz not null default now()
);
create index copy_rules_channel on copy_rules(channel_id) where enabled;

-- ---------------------------------------------------------------- trading
create table shadow_trades (                         -- risk_engine (week-1 "would have traded")
  id            text primary key,
  copy_rule_id  text not null references copy_rules(id),
  signal_id     text not null,
  payload       jsonb not null,
  created_at    timestamptz not null default now()
);

create table trade_intents (                         -- risk_engine
  id                 text primary key,
  idempotency_key    text unique not null,
  copy_rule_id       text not null references copy_rules(id),
  user_id            text not null references users(id),
  broker_account_id  text not null references broker_accounts(id),
  signal_id          text not null,
  action             text not null,
  payload            jsonb not null,
  expires_at         timestamptz not null,
  trace_id           text,
  created_at         timestamptz not null default now()
);

create table orders (                                -- execution_router
  id                 text primary key,
  intent_id          text unique not null,
  user_id            text not null references users(id),
  broker_account_id  text not null references broker_accounts(id),
  action             text not null,
  status             text not null check (status in ('executed','failed','rejected')),
  ticket             text,
  symbol             text,
  side               text,
  volume             double precision,
  price              double precision,
  error              text,
  latency_ms         int,
  trace_id           text,
  created_at         timestamptz not null default now()
);

create table positions (                             -- position_tracker
  id                 text primary key,
  user_id            text not null references users(id),
  broker_account_id  text not null references broker_accounts(id),
  channel_id         text not null,
  source_message_id  bigint not null,
  signal_id          text not null,
  tp_index           int,
  ticket             text not null,
  symbol             text not null,
  side               text,
  volume             double precision,
  open_price         double precision,
  sl                 double precision,
  tp                 double precision,
  status             text not null default 'open' check (status in ('open','closed')),
  close_price        double precision,
  profit             double precision,
  opened_at          timestamptz not null default now(),
  closed_at          timestamptz,
  unique (broker_account_id, ticket)
);
create index positions_lookup on positions(broker_account_id, channel_id, source_message_id) where status = 'open';

-- ---------------------------------------------------------------- billing & notifications
create table plans (
  id            text primary key,
  name          text not null,
  max_accounts  int not null default 1,
  allow_live    boolean not null default false,
  price_cents   int not null default 0,
  currency      text not null default 'KES'
);

create table billing_subscriptions (                 -- billing
  id                  text primary key,
  user_id             text not null references users(id),
  plan_id             text not null references plans(id),
  status              text not null check (status in ('trialing','active','past_due','canceled')),
  provider            text,
  provider_ref        text,
  current_period_end  timestamptz,
  updated_at          timestamptz not null default now()
);
create unique index billing_one_per_user on billing_subscriptions(user_id);

create table invoices (                              -- billing
  id            text primary key,
  user_id       text not null references users(id),
  amount_cents  int not null,
  currency      text not null,
  provider      text not null,
  provider_ref  text unique,
  status        text not null,
  created_at    timestamptz not null default now()
);

create table notification_prefs (
  user_id  text not null references users(id),
  channel  text not null,                            -- log | telegram_bot | email | sms
  address  text,
  events   text[] not null default '{trade.executed,trade.failed,trade.closed,risk.limit_hit}',
  primary key (user_id, channel)
);

-- ---------------------------------------------------------------- audit (append-only)
create table audit_log (
  id         bigserial primary key,
  at         timestamptz not null default now(),
  actor      text not null,
  action     text not null,
  entity     text,
  entity_id  text,
  detail     jsonb not null default '{}'
);
create function audit_log_immutable() returns trigger language plpgsql as $$
begin raise exception 'audit_log is append-only'; end $$;
create trigger audit_log_no_update before update or delete on audit_log
  for each row execute function audit_log_immutable();

-- ---------------------------------------------------------------- row-level security
-- User-facing queries (future web app via Core API) run with `set local app.user_id = ...`
-- under a non-owner role and only see their own rows. Internal workers connect as the
-- schema owner, which bypasses RLS.
do $$
declare t text;
begin
  foreach t in array array['telegram_accounts','subscriptions','broker_accounts','copy_rules',
                           'trade_intents','orders','positions','billing_subscriptions',
                           'invoices','notification_prefs']
  loop
    execute format('alter table %I enable row level security', t);
    execute format('create policy %I on %I using (user_id = current_setting(''app.user_id'', true))',
                   t || '_owner', t);
  end loop;
end $$;
