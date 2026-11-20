<?php

namespace App\Pipstream;

use App\Models\TelegramAccount;
use Illuminate\Support\Facades\Cache;

/**
 * Drives the "Connect Telegram" screens. Each step is sent to the engine (intake worker) on
 * tg.login.request; the answer comes back on tg.login.status, which `pipstream:consume`
 * stores in the cache under the request id. The web request waits a few seconds for it so
 * the phone gets a direct answer instead of polling.
 */
class TelegramLink
{
    public function __construct(private Bus $bus) {}

    public function request(TelegramAccount $acc, string $step, array $extra = [], ?float $wait = null): array
    {
        $rid = Ids::new('tgr');
        $this->bus->publish('tg.login.request', array_merge([
            'request_id' => $rid,
            'telegram_account_id' => $acc->id,
            'user_id' => $acc->user_id,
            'step' => $step,
        ], $extra));

        return $this->wait($rid, $wait ?? (float) config('pipstream.telegram_wait_seconds'));
    }

    public function wait(string $rid, float $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        do {
            if ($status = Cache::get("tglogin:{$rid}")) {
                return $status;
            }
            usleep(150_000);
        } while (microtime(true) < $deadline);

        return ['status' => 'pending', 'request_id' => $rid];
    }

    public function status(string $rid): array
    {
        return Cache::get("tglogin:{$rid}") ?? ['status' => 'pending', 'request_id' => $rid];
    }

    /** Called by the event consumer for every tg.login.status event. */
    public static function remember(array $payload): void
    {
        Cache::put("tglogin:{$payload['request_id']}", $payload, now()->addMinutes(15));
        if (($payload['status'] ?? null) === 'channels') {
            Cache::put("tgchannels:{$payload['telegram_account_id']}", $payload['channels'] ?? [], now()->addHour());
        }
    }

    public static function cachedChannels(string $accountId): ?array
    {
        return Cache::get("tgchannels:{$accountId}");
    }
}
