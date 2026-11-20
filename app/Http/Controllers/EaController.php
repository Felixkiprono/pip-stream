<?php

namespace App\Http\Controllers;

use App\Models\BrokerAccount;
use App\Pipstream\Bus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One endpoint the PipStream EA calls about once a second from the customer's terminal.
 *
 * Request (JSON): {results: [{id, ok, ticket, price, volume, error, retcode, new_ticket}],
 *                  state?: {balance, equity, currency, login, server, company, platform, trade_allowed,
 *                           positions: [{ticket, symbol, side, volume, open_price, sl, tp, profit, magic}]},
 *                  specs?: {SYMBOL: {point, tick_size, tick_value, contract_size, volume_min, volume_max, volume_step}}}
 *
 * Response (text/plain, easy to read in MQL): one header line, then one line per command
 *   OK|<poll_ms>|<server unix time>
 *   C|<id>|<action>|<symbol>|<side>|<order_type>|<volume>|<price>|<sl>|<tp>|<ticket>|<comment>
 *
 * Commands are written only by the engine's executor (Redis ACL): this controller can read them
 * and store answers, so a bug here cannot place a trade.
 */
class EaController extends Controller
{
    public function __construct(private Bus $bus) {}

    private function k(string $id, string $suffix): string
    {
        return config('pipstream.stream_prefix').":eares:{$id}:{$suffix}";
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    private function account(Request $r): ?BrokerAccount
    {
        $key = (string) $r->header('X-PipStream-Key', '');
        if (! preg_match('/^psk_[A-Za-z0-9]{32,64}$/', $key)) {
            return null;
        }
        $hash = self::hashKey($key);
        $id = Cache::remember("ea-key:{$hash}", 60, fn () => BrokerAccount::where('connector', 'ea_bridge')
            ->where('enabled', true)->whereRaw("symbol_map->>'_ea_key_hash' = ?", [$hash])->value('id') ?? '');

        return $id ? BrokerAccount::where('id', $id)->where('enabled', true)->first() : null;
    }

    /** Where the EA should call: PS_EA_URL, else the address the customer is using right now. */
    public static function eaUrl(Request $r): string
    {
        return rtrim((string) (config('pipstream.ea_url') ?: $r->getSchemeAndHttpHost()), '/');
    }

    /** The EA source with the server address preset, so the customer never has to type it. */
    public function download(Request $r, string $ext)
    {
        $src = file_get_contents(resource_path("ea/PipStream.{$ext}"));
        $url = self::eaUrl($r);
        $src = preg_replace('/input string PipStreamUrl = "[^"]*";/', 'input string PipStreamUrl = "'.addcslashes($url, '"\\').'";', $src, 1);

        return response($src)->header('Content-Type', 'application/octet-stream')
            ->header('Content-Disposition', "attachment; filename=\"PipStream.{$ext}\"")
            ->header('Cache-Control', 'no-store');
    }

    /** "Has a MetaTrader reached us from where you are?" so the app can say what's happening before the code is typed. */
    public static function seenFrom(string $ip): ?array
    {
        return Cache::get('ea-pair-ip:'.hash('sha256', $ip));
    }

    /**
     * Pairing for an EA started without a key (no copy-paste for the customer):
     *   first call (no token)  -> PAIR|<token>|<6-digit code>    the EA shows the code on the chart
     *   later calls (token)    -> WAIT|<code>   until the customer types the code in the app,
     *                          -> KEY|<psk_...> once, then the EA saves the key and starts syncing.
     * Codes live 15 minutes. Only the EA holding the token can collect the key.
     */
    public function pair(Request $r)
    {
        $text = fn (string $s, int $code = 200) => response($s."\n", $code)->header('Content-Type', 'text/plain');
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $r->input('token', ''));
        if ($token !== '' && ($p = Cache::get("ea-pair:{$token}"))) {
            if (! empty($p['key'])) {
                Cache::forget("ea-pair:{$token}");

                return $text('KEY|'.$p['key']);
            }

            $this->markSeen($r, $p);

            return $text('WAIT|'.$p['code']);
        }
        $info = fn (string $k, int $max) => mb_substr(preg_replace('/[|\r\n]/', '', (string) $r->input($k, '')), 0, $max);
        do {
            $code = (string) random_int(100000, 999999);
        } while (Cache::has("ea-pair-code:{$code}"));
        $token = Str::random(40);
        Cache::put("ea-pair:{$token}", ['code' => $code, 'key' => null,
            'platform' => $r->input('platform') === 'mt4' ? 'mt4' : 'mt5',
            'login' => $info('login', 30), 'server' => $info('server', 80), 'company' => $info('company', 80)], now()->addMinutes(15));
        Cache::put("ea-pair-code:{$code}", $token, now()->addMinutes(15));
        $this->markSeen($r);

        return $text("PAIR|{$token}|{$code}");
    }

    private function markSeen(Request $r, ?array $p = null): void
    {
        $p ??= ['platform' => $r->input('platform') === 'mt4' ? 'mt4' : 'mt5', 'company' => mb_substr((string) $r->input('company', ''), 0, 80)];
        Cache::put('ea-pair-ip:'.hash('sha256', (string) $r->ip()), ['platform' => $p['platform'], 'company' => $p['company'], 'at' => time()], now()->addMinutes(15));
    }

    /** The customer typed the code shown on their chart: create the account and hand the EA its key. */
    public static function claimPairing(string $code): ?array
    {
        $token = Cache::get("ea-pair-code:{$code}");
        $p = $token ? Cache::get("ea-pair:{$token}") : null;
        if (! $p || ! empty($p['key'])) {
            return null;
        }
        Cache::forget("ea-pair-code:{$code}");

        return ['token' => $token] + $p;
    }

    public static function deliverKey(string $token, array $pairing, string $key): void
    {
        Cache::put("ea-pair:{$token}", ['key' => $key] + $pairing, now()->addMinutes(15));
    }

    public function sync(Request $r)
    {
        $b = $this->account($r);
        if (! $b) {
            return response("ERR|key\n", 401)->header('Content-Type', 'text/plain');
        }
        $redis = $this->bus->redis()->client();
        $now = time();
        $redis->set($this->k($b->id, 'seen'), (string) $now, ['ex' => 86400]);

        foreach (array_slice((array) $r->input('results', []), 0, 50) as $res) {
            $id = preg_replace('/[^A-Za-z0-9]/', '', (string) ($res['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $redis->set($this->k($b->id, "r:{$id}"), json_encode([
                'ok' => (bool) ($res['ok'] ?? false),
                'ticket' => isset($res['ticket']) && $res['ticket'] !== '' && $res['ticket'] !== '0' ? (string) $res['ticket'] : null,
                'new_ticket' => isset($res['new_ticket']) && $res['new_ticket'] !== '0' ? (string) $res['new_ticket'] : null,
                'price' => isset($res['price']) ? (float) $res['price'] : null,
                'volume' => isset($res['volume']) ? (float) $res['volume'] : null,
                'retcode' => isset($res['retcode']) ? (int) $res['retcode'] : null,
                'error' => isset($res['error']) ? mb_substr((string) $res['error'], 0, 200) : null,
            ]), ['ex' => 3600]);
        }
        if (is_array($state = $r->input('state'))) {
            $state['at'] = $now;
            $state['positions'] = array_slice((array) ($state['positions'] ?? []), 0, 300);
            $redis->set($this->k($b->id, 'state'), json_encode($state), ['ex' => 86400]);
        }
        if (is_array($specs = $r->input('specs')) && $specs) {
            $redis->set($this->k($b->id, 'specs'), json_encode(array_slice($specs, 0, 400, true)), ['ex' => 86400]);
        }

        $lines = ['OK|1000|'.$now];
        $stream = config('pipstream.stream_prefix').":acct:{$b->id}:ea:cmd";
        $entries = $redis->xRevRange($stream, '+', '-', 30) ?: [];
        $nowMs = (int) floor(microtime(true) * 1000);
        foreach (array_reverse($entries, true) as $fields) {
            $c = json_decode($fields['c'] ?? '', true);
            if (! is_array($c) || ($c['expires_ms'] ?? 0) < $nowMs) {
                continue;
            }
            $id = preg_replace('/[^A-Za-z0-9]/', '', (string) $c['id']);
            if ($redis->exists($this->k($b->id, "r:{$id}"))) {
                continue;
            }
            $f = fn ($v) => $v === null ? '' : str_replace(['|', "\n", "\r"], '', (string) $v);
            $lines[] = implode('|', ['C', $id, $f($c['action'] ?? ''), $f($c['symbol'] ?? ''), $f($c['side'] ?? ''),
                $f($c['order_type'] ?? 'market'), $f($c['volume'] ?? ''), $f($c['price'] ?? ''), $f($c['sl'] ?? ''),
                $f($c['tp'] ?? ''), $f($c['ticket'] ?? ''), $f($c['comment'] ?? '')]);
        }

        return response(implode("\n", $lines)."\n", 200)->header('Content-Type', 'text/plain');
    }
}
