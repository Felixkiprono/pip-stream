<?php

namespace App\Pipstream;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Mail;

/**
 * Email through Resend (resend.com), like NanoDeploi: Laravel's "resend" mailer. The API key
 * and sender are set by a superadmin in Admin → Email and stored encrypted in
 * platform_settings; RESEND_API_KEY / MAIL_* in .env are the fallback.
 */
final class MailSettings
{
    public const KEY = 'mail';

    /** What is stored from the admin console (key, from_address, from_name, test_to). */
    public static function stored(): array
    {
        return PlatformSetting::read(self::KEY) ?? [];
    }

    /** Point Laravel's mailer at Resend with the admin settings. Called at boot and after a save. */
    public static function apply(): void
    {
        $s = self::stored();
        $env = (array) config('mail.env');
        $key = ($s['resend_key'] ?? null) ?: ($env['resend_key'] ?? null);
        config([
            'services.resend.key' => $key,
            // a key alone is enough to send through Resend; without one .env decides (log locally)
            'mail.default' => $key ? 'resend' : ($env['mailer'] ?? config('mail.default')),
            'mail.from.address' => ($s['from_address'] ?? null) ?: ($env['from_address'] ?? config('mail.from.address')),
            'mail.from.name' => ($s['from_name'] ?? null) ?: ($env['from_name'] ?? config('mail.from.name')),
            'mail.resend_test_to' => ($s['test_to'] ?? null) ?: config('mail.resend_test_to_env'),
        ]);
        Mail::purge('resend');
    }

    /** Where the active key came from: admin | env | none. */
    public static function source(): string
    {
        return ! empty(self::stored()['resend_key']) ? 'admin' : (config('mail.env.resend_key') ? 'env' : 'none');
    }

    /** For the admin screen and the System checks. Never the key itself, only its last 4. */
    public static function present(): array
    {
        $s = self::stored();
        $key = ($s['resend_key'] ?? null) ?: config('mail.env.resend_key');

        return [
            'source' => self::source(),
            'key_hint' => $key ? '••••'.substr($key, -4) : null,
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'test_to' => config('mail.resend_test_to'),
            'mailer' => config('mail.default'),
        ];
    }
}
