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

    // Beta: shown across the app
    'beta' => (bool) env('PS_BETA', true),

    // New customers start here
    'default_plan' => env('PS_DEFAULT_PLAN', 'trial'),
    'trial_days' => (int) env('PS_TRIAL_DAYS', 14),
];
