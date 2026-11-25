<?php

namespace App\Notifications;

use App\Pipstream\PublicUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Your password was changed": after a reset link is used or the password is changed in
 *  Settings, so the owner hears about it if it wasn't them. Sent via Accounts::mailNow. */
class PasswordChanged extends Notification
{
    use Queueable;

    /** @param 'reset'|'settings' $how */
    public function __construct(public string $how = 'reset', public ?string $at = null)
    {
        $this->at ??= now()->utc()->format('j M Y, H:i').' UTC';
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $how = $this->how === 'settings' ? 'from Settings' : 'with a reset link';

        return (new MailMessage)
            ->subject('Your PipStream password was changed')
            ->line("The password for your PipStream account ({$notifiable->email}) was changed {$how} on {$this->at}.")
            ->line('If that was you, there is nothing to do.')
            ->line("If it wasn't you, reset your password right away and check your connected broker accounts.")
            ->action('Reset my password', PublicUrl::to('/forgot-password'))
            ->salutation('The PipStream team');
    }
}
