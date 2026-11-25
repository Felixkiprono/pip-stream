<?php

return [
    // Redis Streams shared with the Python engine (ps:stream:<topic>)
    'stream_prefix' => env('PS_STREAM_PREFIX', 'ps'),
    'stream_maxlen' => (int) env('PS_STREAM_MAXLEN', 100000),
    'consumer_group' => 'laravel',

    // Public halves of the engine's sealed-box keys. Laravel can seal secrets to these but
    // can never open them: only the engine worker with the private key can.
    'intake_public_key' => env('PS_INTAKE_PUBLIC_KEY'),
    'executor_public_key' => env('PS_EXECUTOR_PUBLIC_KEY'),

    // Engine's internal HTTP (parse preview). Private compose network only.
    // The address the PipStream EA calls. Empty: the address the customer downloaded it from.
    'ea_url' => env('PS_EA_URL'),

    // Where links in emails point (password reset, welcome). Empty: APP_URL. In production a
    // local APP_URL falls back to https://pips.stream (App\Pipstream\PublicUrl).
    'public_url' => env('PS_PUBLIC_URL'),

    // Redis without an ACL file (NanoDeploi's managed Redis): `php artisan pipstream:redis-user`
    // logs in with the host's own Redis password and creates Laravel's ACL user "laravel"
    // (rules in resources/redis/laravel.acl, generated from the engine's permissions.py).
    'redis_laravel_password' => env('PS_REDIS_PASSWORD_LARAVEL'),
    'redis_admin' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'username' => env('PS_REDIS_ADMIN_USERNAME'),
        'password' => env('PS_REDIS_ADMIN_PASSWORD', env('REDIS_PASSWORD')),
    ],

    'engine_url' => env('PS_ENGINE_URL', 'http://engine:8001'),

    // How long a web request waits for the engine to answer a Telegram login step
    'telegram_wait_seconds' => (float) env('PS_TELEGRAM_WAIT_SECONDS', 12),

    // Alerts bot (Telegram Bot API) — a separate bot, never a reader account
    'bot_token' => env('PS_TELEGRAM_BOT_TOKEN'),
    'bot_username' => env('PS_TELEGRAM_BOT_USERNAME', 'PipStreamBot'),
    'bot_webhook_secret' => env('PS_TELEGRAM_BOT_WEBHOOK_SECRET'),

    // Payments
    'paystack_secret' => env('PAYSTACK_SECRET_KEY'),
    'paystack_currency' => env('PAYSTACK_CURRENCY', 'KES'),

    // Free for life (Lifetime plan, Pro limits): comma-separated emails, kept in .env
    'lifetime_emails' => array_values(array_filter(array_map(fn ($e) => strtolower(trim($e)),
        explode(',', (string) env('PS_LIFETIME_EMAILS', ''))))),

    // Founding members: the first N customers to sign up are free for life with every feature
    'founding_slots' => (int) env('PS_FOUNDING_SLOTS', 10),

    // Superadmin, created or updated by the migration / `php artisan db:seed --class=SuperadminSeeder --force`.
    // The password is read from the environment only, never committed.
    'superadmin_email' => env('PS_SUPERADMIN_EMAIL', 'felixkiprono@yahoo.com'),
    'superadmin_password' => env('PS_SUPERADMIN_PASSWORD'),

    // Beta: shown across the app
    'beta' => (bool) env('PS_BETA', true),

    // New customers start here
    'default_plan' => env('PS_DEFAULT_PLAN', 'trial'),
    'trial_days' => (int) env('PS_TRIAL_DAYS', 14),
];
