<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Sends alerts through PipStream's own bot (Bot API), never through a reader account. */
class TelegramBotChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $token = config('pipstream.bot_token');
        $chat = DB::table('notification_prefs')->where(['user_id' => $notifiable->id, 'channel' => 'telegram_bot'])->value('address');
        if (! $token || ! $chat) {
            return;
        }
        Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chat, 'text' => $notification->toTelegram($notifiable), 'disable_web_page_preview' => true,
        ])->throw();
    }
}
