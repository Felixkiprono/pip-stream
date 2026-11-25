<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The 6-digit code a customer types into @pip_stream_bot to connect it without the app. */
class BotLinkCode extends Notification
{
    use Queueable;

    public function __construct(public string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->code} is your PipStream code")
            ->line('Type this code in the PipStream bot on Telegram to connect it to your account:')
            ->line("**{$this->code}**")
            ->line('It works for 15 minutes. If you didn\'t ask for it, ignore this email: nothing changes.')
            ->salutation('The PipStream team');
    }
}
