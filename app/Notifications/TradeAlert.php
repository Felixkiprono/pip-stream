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
        if ($prefs->has('telegram_bot') && $prefs['telegram_bot']) {
            $via[] = TelegramBotChannel::class;
        }
        if ($prefs->has('email') && in_array($this->kind, ['trade_failed', 'signal_skipped'], true)) {
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
            'trade_failed' => '⚠️', 'signal_skipped' => '⏸'][$this->kind] ?? '•';

        return "{$icon} {$this->title}\n{$this->body}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('PipStream: '.$this->title)->line($this->body);
    }
}
