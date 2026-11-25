<?php

namespace App\Pipstream;

use App\Models\Channel;
use App\Models\User;
use App\Notifications\Connected;
use App\Notifications\TradeAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** What Laravel does with each engine event it consumes. */
class EventHandler
{
    public function __invoke(array $event): void
    {
        $p = $event['payload'];
        match ($event['topic']) {
            'tg.login.status' => $this->tgStatus($p),
            'trade.executed', 'trade.failed' => $this->trade($p, $event['trace_id'] ?? null),
            'trade.closed' => $this->closed($p),
            'risk.limit_hit' => $this->limit($p),
            'signal.rejected' => Log::info('signal rejected', ['channel_id' => $p['channel_id'] ?? null, 'reason' => $p['reason'] ?? null]),
            default => null,
        };
    }

    private function tgStatus(array $p): void
    {
        TelegramLink::remember($p);
        // "Your Telegram is connected": once per account per day, however many times the event replays
        if (($p['status'] ?? null) === 'linked' && ! empty($p['user_id']) && ! empty($p['telegram_account_id'])
            && Cache::add("mail:tg-linked:{$p['telegram_account_id']}", 1, now()->addDay())
            && ($u = $this->user($p['user_id']))) {
            Accounts::safely(fn () => $u->notify(new Connected('telegram', $p['phone_hint'] ?? null)));
        }
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
            if ($p['limit'] === 'awaiting_confirm') {
                $this->askFirst($u, $p);

                return;
            }
            if ($p['limit'] === 'confirm_rejected') {
                return; // the customer tapped Skip: they know
            }
            if (in_array($p['limit'], ['duplicate_signal', 'duplicate_edit'], true)) {
                $u->notify(new TradeAlert('signal_duplicate', 'Repeat signal not copied', $p['limit'] === 'duplicate_edit'
                    ? 'The channel edited a post you already copied. Only SL/TP changes are applied; it is never opened twice.'
                    : 'The channel posted the same signal again ('.($p['detail'] ?? 'already copied').'), so it was not opened twice.', $p));

                return;
            }
            $why = match ($p['limit']) {
                'kill_switch' => 'copying is paused',
                'no_subscription' => 'your plan has ended',
                'max_open_trades' => 'you reached your open-trade limit',
                'max_daily_loss' => 'today\'s loss limit was reached',
                'sizing' => 'the post gave no price to size the trade from',
                'confirm_expired' => 'you didn\'t tap Copy it within 5 minutes',
                default => str_replace('_', ' ', $p['limit']),
            };
            $u->notify(new TradeAlert('signal_skipped', 'Signal skipped', "Not copied because {$why}.", $p));
        }
    }

    /**
     * "Ask me before each trade": the risk engine holds the signal (pending id in detail) and
     * the customer gets Copy it / Skip buttons in the bot. Without a bot chat it can't ask, so the
     * app says why the signal waits.
     */
    private function askFirst(User $u, array $p): void
    {
        $pid = (string) $p['detail'];
        if (! Cache::add("botasked:{$pid}", 1, now()->addMinutes(10))) {
            return; // event replayed
        }
        $sig = DB::table('signals')->where('id', $p['signal_id'])->first();
        $payload = $sig ? (json_decode($sig->payload, true) ?: []) : [];
        $line = \App\Pipstream\Bot\BotScreens::signalLine($payload + ['symbol' => $sig->symbol ?? null, 'side' => $sig->side ?? null]);
        $ch = Channel::find($sig->channel_id ?? '')?->title ?? 'your channel';
        $acct = DB::table('broker_accounts')->where('id', $p['broker_account_id'])->first();
        Cache::put("botpending:{$pid}", $u->id, now()->addMinutes(6));
        Cache::put("botpendingtext:{$pid}", trim(strtoupper((string) ($payload['side'] ?? '')).' '.($payload['symbol'] ?? '')), now()->addMinutes(6));
        $sent = false;
        \App\Pipstream\Bot\Bot::quietly(function () use ($u, $ch, $line, $acct, $pid, &$sent) {
            $sent = \App\Pipstream\Bot\Bot::toUser($u, "🙋 New signal from {$ch}\n{$line}\n\nCopy it to ".($acct->label ?? 'your account')
                .(($acct->connector ?? '') === 'paper' ? ' (practice)' : '').'? Tap within 5 minutes.',
                [['✅ Copy it' => "cf:y:{$pid}", '✖ Skip' => "cf:n:{$pid}"]]);
        });
        if (! $sent) {
            $u->notify(new TradeAlert('signal_skipped', 'Signal waiting for your OK',
                "{$ch} posted {$line}. You asked to confirm each trade, which happens in the PipStream Telegram bot. Connect it in Settings → Telegram alerts, or turn off \"Ask me first\" for this channel.", $p));
        }
    }

    private static function n($v): string
    {
        return $v === null ? '' : rtrim(rtrim(number_format((float) $v, 5, '.', ''), '0'), '.');
    }
}
