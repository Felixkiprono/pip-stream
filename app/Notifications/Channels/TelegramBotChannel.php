<?php

namespace App\Notifications\Channels;

use App\Pipstream\Bot\BotApi;
use App\Pipstream\Bot\BotSettings;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Sends alerts through PipStream's own bot (@pip_stream_bot, Bot API), never through a
 * customer's reader account. A notification gives toTelegram() and, optionally,
 * telegramButtons() and telegramIsTradeAlert() (trade alerts can be turned off in the bot's
 * Settings; health, security and plan messages always go).
 */
class TelegramBotChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $token = BotSettings::token();
        $chat = DB::table('notification_prefs')->where(['user_id' => $notifiable->id, 'channel' => 'telegram_bot'])->value('address');
        if (! $token || ! $chat) {
            return;
        }
        $c = DB::table('bot_chats')->where('chat_id', $chat)->first();
        if ($c && ($c->blocked_at || (! $c->trade_alerts && method_exists($notification, 'telegramIsTradeAlert') && $notification->telegramIsTradeAlert()))) {
            return;
        }
        $buttons = method_exists($notification, 'telegramButtons') ? $notification->telegramButtons($notifiable) : [];
        (new BotApi($token))->send($chat, $notification->toTelegram($notifiable), $buttons);
    }
}
