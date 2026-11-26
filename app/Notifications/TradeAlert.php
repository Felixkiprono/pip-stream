<?php

namespace App\Notifications;

use App\Notifications\Channels\TelegramBotChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/** One alert about a trade. Always stored for the in-app feed; also sent to the Telegram bot
 *  and/or email when the customer turned those on. */
class TradeAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $kind, public string $title, public string $body, public array $data = []) {}

    public function via(object $notifiable): array
    {
        $prefs = DB::table('notification_prefs')->where('user_id', $notifiable->id)->pluck('address', 'channel');
        $via = ['database'];
        if ($this->kind === 'signal_duplicate') {
            return $via;  // a channel re-posting is common: keep it in the app's feed only
        }
        if ($prefs->has('telegram_bot') && $prefs['telegram_bot']) {
            $via[] = TelegramBotChannel::class;
        }
        if ($prefs->has('email') && in_array($this->kind, ['trade_failed', 'signal_skipped', 'ea_offline', 'ea_online', 'tg_disconnected', 'tg_reconnected', 'payment_failed', 'plan_ending'], true)) {
            $via[] = 'mail';
        }

        return $via;
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body,
            'symbol' => $this->data['symbol'] ?? null, 'ticket' => $this->data['ticket'] ?? null,
            // so the alert opens the full story (post → signal → orders → positions)
            'signal_id' => $this->data['signal_id'] ?? null, 'intent_id' => $this->data['intent_id'] ?? null,
            'broker_account_id' => $this->data['broker_account_id'] ?? null, 'trace_id' => $this->data['trace_id'] ?? null];
    }

    public function toTelegram(object $notifiable): string
    {
        $icon = ['trade_opened' => '✅', 'trade_modified' => '🛡', 'trade_closed' => '📕', 'position_closed' => '🎯',
            'trade_failed' => '⚠️', 'signal_skipped' => '⏸', 'ea_offline' => '🔌', 'ea_online' => '✅',
            'tg_disconnected' => '🔌', 'tg_reconnected' => '✅', 'plan_ending' => '⏰', 'plan_ended' => '⛔',
            'payment_failed' => '💳'][$this->kind] ?? '•';

        return "{$icon} {$this->title}\n{$this->body}";
    }

    /** Buttons under the alert: the next thing to do, in plain words. */
    public function telegramButtons(object $notifiable): array
    {
        $app = fn (string $p) => \App\Pipstream\PublicUrl::to($p);

        return match ($this->kind) {
            'trade_opened', 'trade_modified', 'trade_closed', 'position_closed' => [['📈 My trades' => 'm:trades', '💰 Profit' => 'm:pnl']],
            'trade_failed' => [['🔎 See what happened' => $app('/app/alerts')]],
            'signal_skipped' => [['📊 Status' => 'm:status']],
            'ea_offline' => [['🔧 Fix it in the app' => $app('/app/brokers')]],
            'ea_online', 'tg_reconnected' => [['📊 Status' => 'm:status']],
            'tg_disconnected' => [['🔗 Reconnect Telegram' => $app('/start/telegram')]],
            'plan_ending', 'plan_ended', 'payment_failed' => [['⭐ Renew my plan' => $app('/app/billing')]],
            default => [],
        };
    }

    /** Trades opening/closing can be muted in the bot; health and plan notices can't. */
    public function telegramIsTradeAlert(): bool
    {
        return in_array($this->kind, ['trade_opened', 'trade_modified', 'trade_closed', 'position_closed', 'signal_skipped'], true);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('PipStream: '.$this->title)->line($this->body);
    }
}
