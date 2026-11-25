<?php

namespace App\Http\Controllers;

use App\Models\BrokerAccount;
use App\Models\User;
use App\Notifications\Connected;
use App\Pipstream\Accounts;
use App\Pipstream\Bus;
use App\Pipstream\LiveGate;
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
 *   OK|<poll_ms>|<server unix time>|<1 if new trades may be opened on a real-money account, else 0>
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
        // A public address the customer reached us on always wins: a stale PS_EA_URL (say after a
        // domain change) must never send installers to a host that no longer exists.
        $host = $r->getHost();
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || filter_var($host, FILTER_VALIDATE_IP)
            || str_ends_with($host, '.local') || str_ends_with($host, '.test');
        $configured = (string) config('pipstream.ea_url');

        return rtrim($configured !== '' && $local ? $configured : $r->getSchemeAndHttpHost(), '/');
    }

    /** The EA source with the server address preset, so the customer never has to type it. */
    public function download(Request $r, string $ext)
    {
        if (str_starts_with($ext, 'ex')) {
            return $this->built($ext);
        }
        $src = file_get_contents(resource_path("ea/PipStream.{$ext}"));
        $url = self::eaUrl($r);
        $src = preg_replace('/#define PS_DEFAULT_URL "[^"]*"/', '#define PS_DEFAULT_URL "'.addcslashes($url, '"\\').'"', $src, 1);

        return response($src)->header('Content-Type', 'application/octet-stream')
            ->header('Content-Disposition', "attachment; filename=\"PipStream.{$ext}\"")
            ->header('Cache-Control', 'no-store');
    }

    private function built(string $ext)
    {
        $f = resource_path("ea/build/PipStream.{$ext}");
        abort_unless(is_file($f), 404);

        return response()->download($f, "PipStream.{$ext}", ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-cache']);
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
        $install = (string) $r->input('install', '');
        if (preg_match('/^pst_[A-Za-z0-9]{40}$/', $install) && ($it = Cache::pull('ea-install:'.hash('sha256', $install)))) {
            $b = BrokerAccount::where('id', $it['broker_id'])->where('connector', 'ea_bridge')->first();
            if ($b) {
                $key = self::link($b, $this->pairingInfo($r));
                $this->bus->publish('config.changed', ['entity' => 'broker_account', 'entity_id' => $b->id]);

                return $text('KEY|'.$key);
            }
        }
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $r->input('token', ''));
        if ($token !== '' && ($p = Cache::get("ea-pair:{$token}"))) {
            if (! empty($p['key'])) {
                Cache::forget("ea-pair:{$token}");

                return $text('KEY|'.$p['key']);
            }

            $this->markSeen($r, $p);

            return $text('WAIT|'.$p['code']);
        }
        do {
            $code = (string) random_int(100000, 999999);
        } while (Cache::has("ea-pair-code:{$code}"));
        $token = Str::random(40);
        Cache::put("ea-pair:{$token}", ['code' => $code, 'key' => null] + $this->pairingInfo($r), now()->addMinutes(15));
        Cache::put("ea-pair-code:{$code}", $token, now()->addMinutes(15));
        $this->markSeen($r);

        return $text("PAIR|{$token}|{$code}");
    }

    private function markSeen(Request $r, ?array $p = null): void
    {
        $p ??= ['platform' => $r->input('platform') === 'mt4' ? 'mt4' : 'mt5', 'company' => mb_substr((string) $r->input('company', ''), 0, 80)];
        Cache::put('ea-pair-ip:'.hash('sha256', (string) $r->ip()), ['platform' => $p['platform'], 'company' => $p['company'], 'at' => time()], now()->addMinutes(15));
    }

    private function pairingInfo(Request $r): array
    {
        $info = fn (string $k, int $max) => mb_substr(preg_replace('/[|\r\n]/', '', (string) $r->input($k, '')), 0, $max);

        return ['platform' => $r->input('platform') === 'mt4' ? 'mt4' : 'mt5',
            'login' => $info('login', 30), 'server' => $info('server', 80), 'company' => $info('company', 80)];
    }

    /** Give an EA row a fresh key and name it after the terminal ("MetaTrader 5 · Exness 71908446"). Returns the key. */
    public static function link(BrokerAccount $b, array $p): string
    {
        if ($old = ($b->symbol_map['_ea_key_hash'] ?? null)) {
            Cache::forget("ea-key:{$old}");  // the old key stops working at once
        }
        $key = 'psk_'.Str::random(40);
        $mt = $p['platform'] === 'mt4' ? 'MetaTrader 4' : 'MetaTrader 5';
        $label = mb_substr(trim($mt.' · '.($p['company'] ?: $p['server']).($p['login'] ? ' '.$p['login'] : ''), ' ·'), 0, 60);
        $b->update(['label' => $label, 'enabled' => true, 'symbol_map' => array_merge((array) $b->symbol_map, [
            '_platform' => $p['platform'], '_ea_key_hash' => self::hashKey($key),
            '_login_hint' => $p['login'] ? '•••'.substr($p['login'], -3) : null])]);
        if ($u = User::find($b->user_id)) {
            Accounts::safely(fn () => $u->notify(new Connected('broker', $label)));
        }

        return $key;
    }

    /** One-time token baked into an installer: the EA trades it for its key on first contact. */
    public static function makeInstallToken(BrokerAccount $b, string $platform): string
    {
        $t = 'pst_'.Str::random(40);
        Cache::put('ea-install:'.hash('sha256', $t), ['broker_id' => $b->id, 'platform' => $platform], now()->addDay());

        return $t;
    }

    /** The setup script behind the one-line command (curl ... | bash, or irm ... | iex). Valid until the EA uses the token. */
    public function installScript(Request $r, string $token, string $ext)
    {
        $it = preg_match('/^pst_[A-Za-z0-9]{40}$/', $token) ? Cache::get('ea-install:'.hash('sha256', $token)) : null;
        if (! $it) {
            $msg = 'This PipStream setup link has expired or was already used. Get a new one in PipStream > Brokers.';

            return response($ext === 'sh' ? "echo '{$msg}'\n" : "Write-Host '{$msg}'\r\n", 404)->header('Content-Type', 'text/plain');
        }
        $script = strtr(file_get_contents(resource_path($ext === 'sh' ? 'ea/install-mac.sh' : 'ea/install-windows.ps1')), [
            '__URL__' => self::eaUrl($r), '__TOKEN__' => $token, '__MT__' => $it['platform'] === 'mt4' ? '4' : '5']);

        return response($script)->header('Content-Type', 'text/plain; charset=utf-8')->header('Cache-Control', 'no-store');
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

    /**
     * Live prices from a customer's terminal, for practice accounts to fill "buy now" signals at.
     * Stored as the mid price under the broker's name (EURUSDm) and the plain one (EURUSD), for 2 minutes.
     */
    private function storeQuotes($redis, array $quotes): void
    {
        $p = config('pipstream.stream_prefix');
        foreach (array_slice($quotes, 0, 60, true) as $sym => $q) {
            if (! is_string($sym) || ! preg_match('/^[A-Za-z0-9._#-]{2,30}$/', $sym) || ! is_array($q) || count($q) < 2) {
                continue;
            }
            [$bid, $ask] = [(float) $q[0], (float) $q[1]];
            if ($bid <= 0 || $ask < $bid) {
                continue;
            }
            $mid = (string) round(($bid + $ask) / 2, 6);
            $redis->set("{$p}:quote:{$sym}", $mid, ['ex' => 120]);
            if (preg_match('/^([A-Z0-9]{3,})[^A-Z0-9]/', $sym.'|', $m) && $m[1] !== $sym) {
                $redis->set("{$p}:quote:{$m[1]}", $mid, ['ex' => 120]);
            }
        }
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
            if (($state['broker_connected'] ?? true) !== false) {  // a logged-out terminal shows frozen prices
                $this->storeQuotes($redis, (array) ($state['quotes'] ?? []));
            }
            unset($state['quotes']);
            $state['at'] = $now;
            $state['positions'] = array_slice((array) ($state['positions'] ?? []), 0, 300);
            $redis->set($this->k($b->id, 'state'), json_encode($state), ['ex' => 86400]);
        } else {
            $raw = $redis->get($this->k($b->id, 'state'));
            $state = is_string($raw) ? json_decode($raw, true) : null;
        }
        if (is_array($specs = $r->input('specs')) && $specs) {
            $redis->set($this->k($b->id, 'specs'), json_encode(array_slice($specs, 0, 400, true)), ['ex' => 86400]);
        }

        // the EA refuses new trades on a real-money login the customer has not confirmed
        $allowed = LiveGate::allowsOpening($b, is_array($state) ? $state : null);
        if (! $allowed && is_array($state) && LiveGate::kind($state) === 'real') {
            $this->askLiveInBot($b, $state);
        }
        $lines = ['OK|1000|'.$now.'|'.($allowed ? '1' : '0')];
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

    /** A real-money login waits for the customer's yes: ask once a day in the PipStream bot, with buttons. */
    private function askLiveInBot(\App\Models\BrokerAccount $b, array $state): void
    {
        $login = (string) ($state['login'] ?? '');
        if ($login === '' || ! \Illuminate\Support\Facades\Cache::add("botlive:{$b->id}:{$login}", 1, now()->addDay())) {
            return;
        }
        if (! \App\Models\CopyRule::where('broker_account_id', $b->id)->where('mode', 'live')->where('enabled', true)->exists()) {
            return;
        }
        $d = LiveGate::describe($b, $state);
        $text = "💰 Your MetaTrader is on a REAL-money account\n"
            .trim(($d['company'] ?? '').' · login '.$login.(isset($d['balance']) ? ' · balance '.$d['balance'].' '.($d['currency'] ?? '') : ''), ' ·')
            ."\n\nPipStream won't open trades there until you say yes. Real money can be lost, so only say yes if you meant to copy into this account.";
        \Illuminate\Support\defer(fn () => \App\Pipstream\Bot\Bot::quietly(fn () => \App\Pipstream\Bot\Bot::toUser($b->user_id, $text,
            [['✅ Yes, copy to this real account' => "lv:y:{$b->id}"], ['✖ Not now' => "lv:n:{$b->id}"]])));
    }
}
