<?php

namespace App\Pipstream\Bot;

use App\Models\PlatformSetting;
use App\Pipstream\PublicUrl;
use Illuminate\Support\Str;

/**
 * The PipStream bot's token, username and webhook secret. A superadmin pastes the token from
 * @BotFather in Admin → Telegram bot; it is stored encrypted in platform_settings (like the
 * Resend key) and PS_TELEGRAM_BOT_* in .env are the fallback.
 */
final class BotSettings
{
    public const KEY = 'bot';

    public static function stored(): array
    {
        return PlatformSetting::read(self::KEY) ?? [];
    }

    public static function save(array $s): void
    {
        PlatformSetting::write(self::KEY, $s);
    }

    public static function token(): ?string
    {
        return (self::stored()['token'] ?? null) ?: (config('pipstream.bot_token') ?: null);
    }

    public static function username(): string
    {
        return ltrim((string) ((self::stored()['username'] ?? null) ?: config('pipstream.bot_username')), '@');
    }

    /** The secret in the webhook URL and in Telegram's X-Telegram-Bot-Api-Secret-Token header. */
    public static function secret(): ?string
    {
        return (self::stored()['secret'] ?? null) ?: (config('pipstream.bot_webhook_secret') ?: null);
    }

    public static function newSecret(): string
    {
        return Str::random(40);
    }

    public static function webhookUrl(?string $secret = null): string
    {
        return PublicUrl::to('/webhooks/telegram/'.($secret ?? self::secret()));
    }

    /** Watchdog alerts also go to the operators' own Telegram (admins who linked the bot). */
    public static function opsAlerts(): bool
    {
        return (bool) (self::stored()['ops_alerts'] ?? true);
    }

    public static function source(): string
    {
        return ! empty(self::stored()['token']) ? 'admin' : (config('pipstream.bot_token') ? 'env' : 'none');
    }

    public static function link(string $start = ''): string
    {
        return 'https://t.me/'.self::username().($start !== '' ? '?start='.$start : '');
    }

    /** For the admin screen. Never the token itself, only its last 4. */
    public static function present(): array
    {
        $t = self::token();

        return ['source' => self::source(), 'token_hint' => $t ? '••••'.substr($t, -4) : null,
            'username' => self::username(), 'link' => self::link(), 'webhook_url' => $t && self::secret() ? self::webhookUrl() : null,
            'ops_alerts' => self::opsAlerts(), 'checked' => self::stored()['checked'] ?? null];
    }
}
