<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Reset your password" with a link into the React app (/reset-password). */
class ResetPassword extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/reset-password?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email]));
        $minutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your PipStream password')
            ->line('We got a request to reset the password for your PipStream account.')
            ->action('Choose a new password', $url)
            ->line("This link works for {$minutes} minutes. If you didn't ask for it, ignore this email: your password stays the same.");
    }
}
