<?php

namespace App\Pipstream;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Test this channel": the customer gets a one-time code (PS-XXXXXX) to post in a channel they
 * follow. The engine spots the code, reads the rest of the post like a signal, works out what
 * the customer's copy rule would do, and writes it all to Redis for the app to show. A post with
 * a code is never copied for anyone else, and opens a trade only if the customer asked for it.
 * Engine side: engine/src/pipstream/services/parser/selftest.py.
 */
class ChannelTest
{
    // must match CODE_RE in selftest.py: no I, O, 0 or 1 (easy to mistype)
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const TTL = 1800;

    public function __construct(private Bus $bus) {}

    private function redis()
    {
        return $this->bus->redis()->client();
    }

    private function p(): string
    {
        return config('pipstream.stream_prefix', 'ps');
    }

    public function start(User $u, Channel $c, bool $place): array
    {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $id = 'cht_'.Str::lower(Str::random(16));
        $meta = ['test_id' => $id, 'user_id' => $u->id, 'channel_id' => $c->id, 'place' => $place];
        $now = now();
        $this->redis()->set("{$this->p()}:chtest:code:{$code}", json_encode($meta), ['ex' => self::TTL]);
        $state = $meta + ['state' => 'waiting', 'code' => $code, 'created_at' => $now->toIso8601ZuluString(),
            'expires_at' => $now->copy()->addSeconds(self::TTL)->toIso8601ZuluString()];
        $this->redis()->set("{$this->p()}:chtest:{$id}", json_encode($state), ['ex' => 3600]);

        return $state;
    }

    public function get(User $u, string $id): ?array
    {
        if (! preg_match('/^cht_[a-z0-9]{16}$/', $id)) {
            return null;
        }
        $raw = $this->redis()->get("{$this->p()}:chtest:{$id}");
        $t = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($t) && ($t['user_id'] ?? null) === $u->id ? $t : null;
    }

    /** Points either side of the price for the sample's stop-loss and take-profit. */
    public const SAMPLE_DISTANCE = 15;

    /**
     * A sample gold signal to paste, built around today's price so it reads like a real post:
     * the live price from a connected MetaTrader (fresh within 2 minutes), else the last live
     * price we saw (kept 3 days), else the latest gold price a channel posted in the last 2 days.
     * With no recent price at all the stop-loss and take-profit are given in pips instead.
     */
    public function sample(): string
    {
        $px = $this->goldPrice();
        if ($px === null) {
            return "XAUUSD BUY NOW\nSL: 30 pips\nTP: 60 pips";
        }
        $px = (int) round($px);
        $d = self::SAMPLE_DISTANCE;

        return "XAUUSD BUY NOW @ {$px}\nSL: ".($px - $d)."\nTP: ".($px + $d);
    }

    private function goldPrice(): ?float
    {
        foreach (['XAUUSD', 'last:XAUUSD'] as $k) {
            $q = rescue(fn () => $this->redis()->get("{$this->p()}:quote:{$k}"), null, false);
            if (is_numeric($q) && $q > 0) {
                return (float) $q;
            }
        }
        $s = DB::table('signals')->where('symbol', 'XAUUSD')->where('status', 'parsed')->where('kind', 'open')
            ->where('created_at', '>=', now()->subDays(2))->orderByDesc('created_at')->value('payload');
        $sig = $s ? (json_decode($s, true) ?: []) : [];
        $e = $sig['entry'] ?? [];
        $px = $e['price'] ?? (isset($e['from'], $e['to']) ? ($e['from'] + $e['to']) / 2 : null)
            ?? (isset($sig['sl'], $sig['tps'][0]) ? ($sig['sl'] + $sig['tps'][0]) / 2 : null);

        return is_numeric($px) && $px > 0 ? (float) $px : null;
    }

    /** What the app shows: the engine's raw result, plus plain-English lines. */
    public function present(array $t, Channel $c): array
    {
        $expired = ($t['state'] ?? '') === 'waiting' && isset($t['expires_at']) && now()->greaterThan($t['expires_at']);
        $state = $expired ? 'expired' : ($t['state'] ?? 'waiting');
        $arr = $t['arrived'] ?? null;
        $delay = null;
        if ($arr && ! empty($arr['posted_at']) && ! empty($arr['received_at'])) {
            $delay = max(0, round(\Illuminate\Support\Carbon::parse($arr['received_at'])->getTimestampMs()
                - \Illuminate\Support\Carbon::parse($arr['posted_at'])->getTimestampMs()) / 1000);
        }
        $parse = $t['parse'] ?? null;
        $plan = $t['plan'] ?? null;
        $broker = $plan && ! empty($plan['broker_account_id'])
            ? DB::table('broker_accounts')->where('id', $plan['broker_account_id'])->value('label') : null;
        $other = $state === 'wrong_channel' && $arr ? DB::table('channels')->where('id', $arr['channel_id'])->value('title') : null;

        return [
            'id' => $t['test_id'], 'code' => $t['code'] ?? null, 'state' => $state, 'done' => $expired || ($t['done'] ?? false),
            'place' => (bool) ($t['place'] ?? false), 'created_at' => $t['created_at'] ?? null, 'expires_at' => $t['expires_at'] ?? null,
            'arrived' => $arr ? ['posted_at' => $arr['posted_at'] ?? null, 'received_at' => $arr['received_at'] ?? null,
                'seconds' => $delay, 'edited' => ($arr['edit_version'] ?? 0) > 0] : null,
            'wrong_channel' => $other,
            'parse' => $parse ? [
                'status' => $parse['status'], 'confidence' => $parse['confidence'] ?? null,
                'text' => match ($parse['status']) {
                    'ping' => 'Only the code was posted, so there was no signal to read. That is fine for a delivery check.',
                    'rejected' => 'Not read as a signal: '.self::reason($parse['reason'] ?? '').'.',
                    default => 'Read as: '.self::summary($parse['signal'] ?? []),
                },
                'signal' => $parse['signal'] ?? null,
            ] : null,
            'plan' => $plan ? [
                'status' => $plan['status'], 'mode' => $plan['mode'] ?? null, 'broker' => $broker,
                'legs' => $plan['legs'] ?? [], 'signal_id' => $plan['signal_id'] ?? null,
                'text' => match ($plan['status']) {
                    'no_rule' => 'Nothing would be copied: copying is off for this channel, or its broker is switched off.',
                    'skipped' => 'Would not be copied: '.self::limit($plan['limit'] ?? '', $plan['detail'] ?? '').'.',
                    'placed' => 'Placed on '.($broker ?? 'your account').'. You will see it under Trades.',
                    default => (($plan['mode'] ?? '') === 'shadow' ? 'Watch only, so no order. It would be: ' : 'Would copy to '.($broker ?? 'your account').': ')
                        .self::legs($plan['legs'] ?? []),
                },
            ] : null,
        ];
    }

    private static function n($v): string
    {
        return $v === null ? '' : rtrim(rtrim(number_format((float) $v, 5, '.', ''), '0'), '.');
    }

    public static function summary(array $s): string
    {
        $kind = $s['kind'] ?? 'open';
        if ($kind === 'modify') {
            return ($s['move_sl_to_entry'] ?? false) ? 'move stop-loss to entry'
                : (trim((isset($s['new_sl']) ? 'new SL '.self::n($s['new_sl']).' ' : '').(isset($s['new_tp']) ? 'new TP '.self::n($s['new_tp']) : '')) ?: 'update SL/TP');
        }
        if ($kind === 'close') {
            return 'close '.(($s['close_fraction'] ?? 1) < 1 ? round($s['close_fraction'] * 100).'%' : 'all');
        }
        if ($kind === 'options') {
            $o = $s['options'] ?? [];

            return strtoupper($o['contract'] ?? '').' '.($s['symbol'] ?? '').' '.($o['duration'] ?? '').($o['duration_unit'] ?? '')
                .(isset($o['stake']) ? ' · stake '.self::n($o['stake']) : '');
        }
        $e = $s['entry'] ?? [];
        $at = match ($e['type'] ?? 'market') {
            'range' => ' '.self::n($e['from'] ?? null).'–'.self::n($e['to'] ?? null),
            'limit', 'stop' => ' '.$e['type'].' @ '.self::n($e['price'] ?? null),
            default => isset($e['price']) ? ' @ '.self::n($e['price']) : ' at market',
        };
        $sl = isset($s['sl']) ? ' · SL '.self::n($s['sl']) : (isset($s['sl_pips']) ? ' · SL '.self::n($s['sl_pips']).' pips' : ' · no SL');
        $tps = ! empty($s['tps']) ? ' · TP '.implode(' / ', array_map(fn ($t) => self::n($t), $s['tps']))
            : (! empty($s['tp_pips']) ? ' · TP '.implode(' / ', array_map(fn ($t) => self::n($t), $s['tp_pips'])).' pips' : '');

        return strtoupper($s['side'] ?? '').' '.($s['symbol'] ?? '?').$at.$sl.$tps;
    }

    private static function legs(array $legs): string
    {
        if (! $legs) {
            return 'no orders';
        }
        if (($legs[0]['options'] ?? null) !== null) {
            return 'stake '.self::n($legs[0]['volume']).' on '.$legs[0]['symbol'];
        }
        $total = array_sum(array_map(fn ($l) => (float) $l['volume'], $legs));

        return self::n($total).' lots of '.$legs[0]['symbol'].(count($legs) > 1 ? ' in '.count($legs).' trades, one per TP' : '');
    }

    public static function reason(string $r): string
    {
        return match ($r) {
            'empty', 'media_only' => 'the post had no text',
            'ignored_pattern' => 'it matches this channel\'s ignore list (promos, adverts)',
            'info_update', 'noise_prefilter' => 'there is no buy/sell with a price in it',
            'no_symbol' => 'no instrument we know (like XAUUSD or EURUSD)',
            'conflicting_sides' => 'it says both buy and sell',
            'missing_sl' => 'it has no stop-loss, which this channel requires',
            'unclassified' => 'it was not clear enough to trade safely',
            default => str_replace('_', ' ', $r) ?: 'unclear',
        };
    }

    public static function limit(string $l, string $detail = ''): string
    {
        return match ($l) {
            'global_pause' => 'PipStream has paused copying for everyone for a moment',
            'kill_switch' => 'you have paused copying (Home → Pause)',
            'no_subscription' => 'your plan has ended',
            'plan_no_live' => 'your plan copies to Practice only; real-money accounts need Pro',
            'max_open_trades' => 'you reached your open-trade limit',
            'max_daily_loss' => 'today\'s loss limit was reached',
            'sizing' => 'the post gave no price to size the trade from',
            'missing_sl' => 'the post has no stop-loss and your settings require one',
            'no_account_snapshot' => 'your broker account has not reported in yet (is MetaTrader open with the EA running?)',
            'symbol_unavailable' => "your broker doesn't offer {$detail}",
            'below_min_lot' => 'the trade size came out below your broker\'s minimum lot',
            'unsupported_signal_kind' => 'this kind of signal can\'t go to this account type',
            'no_open_positions' => 'it is a follow-up, and there is no open trade from this channel for it to change',
            'symbol_not_allowed', 'symbol_denied' => 'this instrument is filtered out in your copy settings',
            'side_not_allowed' => 'this direction is filtered out in your copy settings',
            'outside_trading_days', 'outside_trading_hours' => 'it is outside the trading hours in your copy settings',
            'below_rule_confidence' => 'the post was not clear enough for your settings',
            'entry_slippage_exceeded' => 'the price has already moved too far from the entry',
            default => str_replace('_', ' ', $l),
        };
    }
}
