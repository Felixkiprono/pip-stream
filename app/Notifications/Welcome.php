<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent once after sign-up. Founding members are told what they got. Transactional. */
class Welcome extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ?int $founding = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $first = trim(strtok((string) $notifiable->name, ' ') ?: '');
        $m = (new MailMessage)
            ->subject($this->founding ? "Welcome, founding member #{$this->founding}" : 'Welcome to PipStream')
            ->greeting($first ? "Welcome, {$first}!" : 'Welcome!')
            ->line('PipStream copies the signals from the Telegram channels you follow straight to your broker.');
        if ($this->founding) {
            $m->line("You are founding member **#{$this->founding}**: PipStream is free for you for life, with every Pro feature unlocked (live trading, hosted MetaTrader and more broker accounts). Thank you for being here from the start.");
        } else {
            $m->line('You start with a free trial and a practice account, so you can watch it copy trades before any real money is involved.');
        }

        return $m->line('Next: link your Telegram, pick the channels to copy and choose a copy style. It takes about two minutes.')
            ->action('Finish setting up', url('/start/telegram'))
            ->salutation('Happy trading, the PipStream team');
    }
}
