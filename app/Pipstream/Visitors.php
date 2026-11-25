<?php

namespace App\Pipstream;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** Landing page visitors: record a page view, then work out where the IP is (after the response). */
class Visitors
{
    private const BOTS = '/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegrambot|headless|lighthouse|python|curl|wget|go-http|axios|node-fetch|monitor|uptime/i';

    public static function isBot(?string $ua): bool
    {
        return ! $ua || (bool) preg_match(self::BOTS, $ua);
    }

    /** The visitor id for this browser session (kept across sign-up). */
    public static function id(Request $r): string
    {
        $id = $r->session()->get('ps_vid');
        if (! $id) {
            $id = (string) Str::ulid();
            $r->session()->put('ps_vid', $id);
        }

        return $id;
    }

    public function record(Request $r, array $d): ?int
    {
        $ua = (string) $r->userAgent();
        if (self::isBot($ua)) {
            return null;
        }
        $ref = $d['referrer'] ?? null;
        $refHost = $ref ? parse_url($ref, PHP_URL_HOST) : null;
        if ($refHost && strcasecmp($refHost, $r->getHost()) === 0) {
            $refHost = null; // moving around our own site is not a referral
            $ref = null;
        }
        [$device, $browser, $os] = self::agent($ua);
        $cf = strtoupper((string) $r->header('CF-IPCountry'));
        $id = DB::table('landing_visits')->insertGetId([
            'visitor_id' => self::id($r), 'user_id' => $r->user()?->id, 'ip' => (string) $r->ip(),
            'country' => preg_match('/^[A-Z]{2}$/', $cf) && $cf !== 'XX' ? $cf : null,
            'device' => $device, 'browser' => $browser, 'os' => $os,
            'path' => Str::limit($d['path'] ?? '/', 290, ''), 'referrer' => $ref ? Str::limit($ref, 490, '') : null,
            'referrer_host' => $refHost ? Str::limit(preg_replace('/^www\./', '', strtolower($refHost)), 190, '') : null,
            'utm_source' => $d['utm_source'] ?? null, 'utm_medium' => $d['utm_medium'] ?? null, 'utm_campaign' => $d['utm_campaign'] ?? null,
            'user_agent' => Str::limit($ua, 1000, ''), 'created_at' => now(),
        ]);

        return $id;
    }

    /** Fill country/region/city/network for a visit: from an earlier visit by the same IP, or a lookup. */
    public function locate(int $visitId): void
    {
        $v = DB::table('landing_visits')->find($visitId);
        if (! $v || $v->city) {
            return;
        }
        $known = DB::table('landing_visits')->where('ip', $v->ip)->where('id', '<>', $v->id)
            ->where('created_at', '>=', now()->subDays(30))->whereNotNull('city')->orderByDesc('id')->first(['country', 'region', 'city', 'org']);
        $geo = $known ? (array) $known : $this->lookup($v->ip);
        if ($geo) {
            DB::table('landing_visits')->where('id', $v->id)->update(array_filter($geo, fn ($x) => $x !== null && $x !== '') + ['country' => $v->country]);
        }
    }

    private function lookup(string $ip): ?array
    {
        if (config('pipstream.geo_provider') !== 'ipinfo' || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
        try {
            $j = Http::timeout(3)->get("https://ipinfo.io/{$ip}/json", array_filter(['token' => config('pipstream.ipinfo_token')]))->throw()->json();
        } catch (Throwable) {
            return null;
        }
        if (! is_array($j) || ! empty($j['bogon'])) {
            return null;
        }

        return ['country' => isset($j['country']) ? strtoupper(substr($j['country'], 0, 2)) : null,
            'region' => isset($j['region']) ? Str::limit($j['region'], 95, '') : null,
            'city' => isset($j['city']) ? Str::limit($j['city'], 95, '') : null,
            'org' => isset($j['org']) ? Str::limit($j['org'], 195, '') : null];
    }

    /** Rough device, browser and OS from the user agent (enough for a table, not fingerprinting). */
    public static function agent(string $ua): array
    {
        $device = preg_match('/iPad|Tablet|(Android(?!.*Mobile))/i', $ua) ? 'tablet' : (preg_match('/Mobi|iPhone|Android/i', $ua) ? 'mobile' : 'desktop');
        $browser = match (true) {
            (bool) preg_match('/Edg\//', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/', $ua) => 'Opera',
            (bool) preg_match('/SamsungBrowser/', $ua) => 'Samsung Internet',
            (bool) preg_match('/FBAN|FBAV|Instagram/', $ua) => 'In-app (Meta)',
            (bool) preg_match('/Firefox|FxiOS/', $ua) => 'Firefox',
            (bool) preg_match('/Chrome|CriOS/', $ua) => 'Chrome',
            (bool) preg_match('/Safari/', $ua) => 'Safari',
            default => 'Other',
        };
        $os = match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
            (bool) preg_match('/Android/', $ua) => 'Android',
            (bool) preg_match('/Windows/', $ua) => 'Windows',
            (bool) preg_match('/Mac OS X|Macintosh/', $ua) => 'macOS',
            (bool) preg_match('/Linux/', $ua) => 'Linux',
            default => 'Other',
        };

        return [$device, $browser, $os];
    }

    /** A visitor who just signed up: link their earlier landing visits to the new account. */
    public static function claim(Request $r, string $userId): void
    {
        if ($vid = $r->session()->get('ps_vid')) {
            DB::table('landing_visits')->where('visitor_id', $vid)->whereNull('user_id')->update(['user_id' => $userId]);
        }
    }
}
