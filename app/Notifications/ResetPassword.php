<?php

namespace App\Notifications;

use App\Pipstream\PublicUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Reset your password" with a link into the React app (https://pips.stream/reset-password).
 *  Sent straight from the web process (Accounts::mailNow), so it never waits on the queue. */
class ResetPassword extends Notification
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = PublicUrl::to('/reset-password', ['token' => $this->token, 'email' => $notifiable->email]);
        $minutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your PipStream password')
            ->line('We got a request to reset the password for your PipStream account.')
            ->action('Choose a new password', $url)
            ->line("This link works for {$minutes} minutes and can be used once. If you didn't ask for it, ignore this email: your password stays the same.")
            ->salutation('The PipStream team');
    }
}
