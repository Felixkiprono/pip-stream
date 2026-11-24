<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Admin → Email → "Send test email". Sent now (not queued) so the admin sees any error. */
class TestEmail extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('PipStream test email')
            ->line('Email from PipStream works. Password resets, welcome emails and connection notices will reach your customers.')
            ->line('Sent from '.config('mail.from.address').' through '.config('mail.default').'.');
    }
}
