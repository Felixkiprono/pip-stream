<?php

namespace App\Notifications;

use App\Pipstream\PublicUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "It worked" emails: Telegram linked, broker connected, plan activated. Transactional. */
class Connected extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param 'telegram'|'broker'|'plan' $what */
    public function __construct(public string $what, public ?string $detail = null) {}

    public function via(object $notifiable): array
    {
        return \App\Pipstream\Bot\Bot::chatOf($notifiable->id) ? ['mail', \App\Notifications\Channels\TelegramBotChannel::class] : ['mail'];
    }

    public function toTelegram(object $notifiable): string
    {
        return match ($this->what) {
            'telegram' => "✅ Your Telegram is connected\nPipStream can now read your channels. Next: pick the channels to copy.",
            'broker' => '✅ '.($this->detail ?: 'Your broker account')." is connected\nSignals from your channels are copied to it with the style you picked.",
            'plan' => '⭐ Your '.($this->detail ?: 'Pro')." plan is active\nThanks for your payment! Real-money copying and every Pro feature are on.",
            default => (string) $this->detail,
        };
    }

    public function telegramButtons(object $notifiable): array
    {
        return $this->what === 'telegram' ? [['➕ Follow a channel' => 'm:follow']] : [['📊 Status' => 'm:status']];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $lines, $cta, $path] = match ($this->what) {
            'telegram' => ['Your Telegram is connected',
                ['PipStream is now linked to your Telegram account'.($this->detail ? " (number ending {$this->detail})" : '').'.',
                    'Next, pick the channels and groups whose signals you want copied.'],
                'Choose channels', '/start/channels'],
            'broker' => ['Your broker account is connected',
                [($this->detail ?: 'Your broker account').' is connected to PipStream and ready to receive trades.',
                    'Signals from the channels you follow are copied to it using the copy style you picked.'],
                'Open PipStream', '/app/brokers'],
            'plan' => ['Your '.($this->detail ?: 'Pro').' plan is active',
                ['Thanks for your payment. Your '.($this->detail ?: 'Pro').' plan is active, with live trading and every Pro feature.'],
                'Open PipStream', '/app/billing'],
            default => ['PipStream', [(string) $this->detail], 'Open PipStream', '/app'],
        };
        $m = (new MailMessage)->subject($subject);
        foreach ($lines as $l) {
            $m->line($l);
        }

        return $m->action($cta, PublicUrl::to($path))
            ->line('If this wasn\'t you, sign in and disconnect it in Settings, then change your password.');
    }
}
