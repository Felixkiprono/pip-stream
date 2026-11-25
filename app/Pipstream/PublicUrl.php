<?php

namespace App\Pipstream;

/**
 * The address links in emails point to. Emails are built in the queue worker too, where there
 * is no request to borrow a host from, so this never depends on one: PS_PUBLIC_URL, else
 * APP_URL. In production a local or missing APP_URL falls back to https://pips.stream and the
 * scheme is always https, so a reset link can never point at localhost or plain http.
 */
final class PublicUrl
{
    public const DEFAULT = 'https://pips.stream';

    public static function base(): string
    {
        $url = rtrim((string) (config('pipstream.public_url') ?: config('app.url')), '/');
        if (! app()->environment('production')) {
            return $url !== '' ? $url : 'http://localhost';
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || self::isLocal($host)) {
            return self::DEFAULT;
        }
        $port = parse_url($url, PHP_URL_PORT);

        return 'https://'.$host.($port && $port !== 443 && $port !== 80 ? ':'.$port : '');
    }

    /** An absolute link into the app, e.g. to('/reset-password', ['token' => …]). */
    public static function to(string $path, array $query = []): string
    {
        return self::base().'/'.ltrim($path, '/').($query ? '?'.http_build_query($query) : '');
    }

    public static function isLocal(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true) || filter_var($host, FILTER_VALIDATE_IP)
            || str_ends_with($host, '.local') || str_ends_with($host, '.test') || ! str_contains($host, '.');
    }
}
