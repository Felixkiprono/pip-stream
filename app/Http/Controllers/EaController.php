<?php

namespace App\Http\Controllers;

use App\Models\BrokerAccount;
use App\Pipstream\Bus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

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
