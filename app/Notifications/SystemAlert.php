<?php

namespace App\Notifications;

use App\Pipstream\PublicUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the operators: something is down, or back up. Sent straight from the scheduler, never the queue
 *  (the queue may be what is down). */
class SystemAlert extends Notification
{
    use Queueable;

    public function __construct(public string $title, public string $detail, public bool $recovered = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->recovered ? '✅ Recovered: ' : '🚨 ').$this->title)
            ->greeting($this->recovered ? 'Back to normal' : 'PipStream needs a look')
            ->line($this->detail)
            ->action('Open System', PublicUrl::to('/admin/system'))
            ->salutation('PipStream watchdog');
    }
}
