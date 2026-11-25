<?php

namespace App\Pipstream\Bot;

use App\Models\AuditLog;
use App\Models\User;
use App\Pipstream\PublicUrl;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Who the bot talks to. A customer is linked when their chat id is stored as the
 * notification_prefs row "telegram_bot" (what the alert code reads) and in bot_chats.
 */
final class Bot
{
    public static function chatOf(User|string $user): ?int
    {
        $id = $user instanceof User ? $user->id : $user;
        $chat = DB::table('notification_prefs')->where(['user_id' => $id, 'channel' => 'telegram_bot'])->value('address');

        return $chat !== null && $chat !== '' ? (int) $chat : null;
    }

    public static function chat(int $chatId): ?object
    {
        return DB::table('bot_chats')->where('chat_id', $chatId)->first();
    }

    /** Remember a chat that wrote to the bot (name, last seen; unblocked again). */
    public static function touch(array $from, int $chatId): object
    {
        DB::table('bot_chats')->upsert([[
            'chat_id' => $chatId, 'username' => isset($from['username']) ? mb_substr($from['username'], 0, 100) : null,
            'first_name' => isset($from['first_name']) ? mb_substr($from['first_name'], 0, 120) : null,
            'last_seen_at' => now(), 'blocked_at' => null, 'created_at' => now(),
        ]], ['chat_id'], ['username', 'first_name', 'last_seen_at', 'blocked_at']);

        return self::chat($chatId);
    }

    /** Link this chat to the customer: alerts, buttons and the support inbox know who it is. */
    public static function link(int $chatId, User $user, string $how): void
    {
        DB::transaction(function () use ($chatId, $user) {
            // one chat per customer and one customer per chat
            DB::table('notification_prefs')->where('channel', 'telegram_bot')->where('address', (string) $chatId)
                ->where('user_id', '<>', $user->id)->delete();
            DB::table('bot_chats')->where('user_id', $user->id)->where('chat_id', '<>', $chatId)->update(['user_id' => null, 'linked_at' => null]);
            DB::table('notification_prefs')->upsert([['user_id' => $user->id, 'channel' => 'telegram_bot', 'address' => (string) $chatId]],
                ['user_id', 'channel'], ['address']);
            DB::table('bot_chats')->upsert([['chat_id' => $chatId, 'user_id' => $user->id, 'linked_at' => now(), 'created_at' => now(), 'state' => null]],
                ['chat_id'], ['user_id', 'linked_at', 'state']);
            DB::table('bot_messages')->where('chat_id', $chatId)->whereNull('user_id')->update(['user_id' => $user->id]);
        });
        AuditLog::record($user->id, 'bot_linked', 'user', $user->id, ['how' => $how]);
    }

    public static function unlink(User $user): void
    {
        DB::table('notification_prefs')->where(['user_id' => $user->id, 'channel' => 'telegram_bot'])->delete();
        DB::table('bot_chats')->where('user_id', $user->id)->update(['user_id' => null, 'linked_at' => null]);
    }

    public static function userOfChat(int $chatId): ?User
    {
        $uid = DB::table('notification_prefs')->where('channel', 'telegram_bot')->where('address', (string) $chatId)->value('user_id');

        return $uid ? User::find($uid) : null;
    }

    /** Send to a customer's linked chat. False when they have none (or blocked the bot). */
    public static function toUser(User|string $user, string $text, array $buttons = []): bool
    {
        $chat = self::chatOf($user);
        if (! $chat || DB::table('bot_chats')->where('chat_id', $chat)->whereNotNull('blocked_at')->exists()) {
            return false;
        }
        try {
            return BotApi::make()->send($chat, $text, $buttons) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /** Never lets a bot problem break the caller (sign-in, a webhook, the watchdog). */
    public static function quietly(callable $fn): void
    {
        try {
            if (BotSettings::token()) {
                $fn();
            }
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('bot side effect failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    /** The staff who get watchdog alerts on Telegram: admins and the superadmin who linked the bot. */
    public static function operatorChats(): array
    {
        return DB::table('users')->join('notification_prefs', 'notification_prefs.user_id', '=', 'users.id')
            ->where('notification_prefs.channel', 'telegram_bot')->whereIn('users.role', ['admin', 'superadmin'])
            ->pluck('notification_prefs.address')->map(fn ($c) => (int) $c)->unique()->values()->all();
    }

    public static function toOperators(string $text, array $buttons = []): int
    {
        if (! BotSettings::opsAlerts() || ! BotSettings::token()) {
            return 0;
        }
        $n = 0;
        foreach (self::operatorChats() as $chat) {
            try {
                $n += BotApi::make()->send($chat, $text, $buttons) !== null ? 1 : 0;
            } catch (Throwable) {
            }
        }

        return $n;
    }

    public static function app(string $path = '/app'): string
    {
        return PublicUrl::to($path);
    }
}
