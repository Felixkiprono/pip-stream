<?php

namespace App\Pipstream;

use App\Models\Channel;
use App\Models\User;
use App\Notifications\TradeAlert;
use Illuminate\Support\Facades\Log;

/** What Laravel does with each engine event it consumes. */
class EventHandler
{
    public function __invoke(array $event): void
    {
        $p = $event['payload'];
        match ($event['topic']) {
            'tg.login.status' => TelegramLink::remember($p),
            'trade.executed', 'trade.failed' => $this->trade($p, $event['trace_id'] ?? null),
            'trade.closed' => $this->closed($p),
            'risk.limit_hit' => $this->limit($p),
            'signal.rejected' => Log::info('signal rejected', ['channel_id' => $p['channel_id'] ?? null, 'reason' => $p['reason'] ?? null]),
            default => null,
        };
    }

    private function user(string $id): ?User
    {
        return User::find($id);
    }

    private function trade(array $p, ?string $trace): void
    {
        $u = $this->user($p['user_id']);
        if (! $u) {
            return;
        }
        $ch = Channel::find($p['channel_id'] ?? '')?->title ?? 'your channel';
        $sym = $p['symbol'];
        $side = strtoupper((string) ($p['side'] ?? ''));
        $practice = ($p['mode'] ?? '') === 'paper' ? ' (practice)' : '';
        if ($p['status'] === 'failed') {
            $u->notify(new TradeAlert('trade_failed', "Couldn't copy {$sym}", trim(($p['error'] ?? 'The broker refused the order').' · '.$ch), $p));

            return;
        }
        [$kind, $title] = match ($p['action']) {
            'open' => ['trade_opened', ($side === 'SELL' ? 'Sold ' : 'Bought ').self::n($p['volume']).' '.$sym.$practice],
            'modify' => ['trade_modified', "Updated {$sym} #{$p['ticket']}{$practice}"],
            'close' => ['trade_closed', 'Closed '.self::n($p['closed_volume'] ?? $p['volume']).' '.$sym.$practice],
        };
        $body = match ($p['action']) {
            'open' => '@ '.self::n($p['price']).($p['sl'] ? ' · SL '.self::n($p['sl']) : '').($p['tp'] ? ' · TP '.self::n($p['tp']) : '').' · '.$ch,
            'modify' => trim(($p['sl'] ? 'SL '.self::n($p['sl']).' ' : '').($p['tp'] ? 'TP '.self::n($p['tp']) : '')).' · '.$ch,
            'close' => ($p['profit'] !== null ? 'Result '.self::n($p['profit']).' · ' : '').$ch,
        };
        $u->notify(new TradeAlert($kind, $title, $body, $p + ['trace_id' => $trace]));
    }

    private function closed(array $p): void
    {
        if ($u = $this->user($p['user_id'])) {
            $u->notify(new TradeAlert('position_closed', "{$p['symbol']} #{$p['ticket']} closed",
                $p['reason'] === 'broker' ? 'Closed at your broker (stop-loss, take-profit or by hand).' : 'Closed by the channel.', $p));
        }
    }

    private function limit(array $p): void
    {
        if ($u = $this->user($p['user_id'])) {
            $why = match ($p['limit']) {
                'kill_switch' => 'copying is paused',
                'no_subscription' => 'your plan has ended',
                'max_open_trades' => 'you reached your open-trade limit',
                'max_daily_loss' => 'today\'s loss limit was reached',
                'sizing' => 'the post gave no price to size the trade from',
                default => str_replace('_', ' ', $p['limit']),
            };
            $u->notify(new TradeAlert('signal_skipped', 'Signal skipped', "Not copied because {$why}.", $p));
        }
    }

    private static function n($v): string
    {
        return $v === null ? '' : rtrim(rtrim(number_format((float) $v, 5, '.', ''), '0'), '.');
    }
}
