<?php

namespace App\Pipstream\Bot;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * A small Telegram Bot API client for @pip_stream_bot. Buttons are given as rows of
 * [label => callback data] or [label => https URL]:
 *
 *   BotApi::send($chat, 'Copy it?', [['✅ Yes' => 'ok:1', '✖ No' => 'no:1'], ['Open app' => 'https://…']]);
 *
 * Messages are plain text (no Markdown parsing), so a channel title or a customer's name can
 * never break a message.
 */
class BotApi
{
    public function __construct(private ?string $token = null) {}

    public static function make(?string $token = null): self
    {
        return new self($token ?? BotSettings::token());
    }

    public function ready(): bool
    {
        return (bool) $this->token;
    }

    /** Raw call. Throws on a network error or when Telegram answers ok=false. */
    public function call(string $method, array $params = []): mixed
    {
        if (! $this->token) {
            throw new RuntimeException('The PipStream bot is not set up (no token).');
        }
        $res = Http::timeout(15)->acceptJson()->post("https://api.telegram.org/bot{$this->token}/{$method}", $params);
        $j = $res->json();
        if (! is_array($j) || ! ($j['ok'] ?? false)) {
            throw new BotApiError((string) ($j['description'] ?? 'HTTP '.$res->status()), (int) ($j['error_code'] ?? $res->status()));
        }

        return $j['result'] ?? null;
    }

    /** Send a message. Returns Telegram's message id, or null when it could not be delivered. */
    public function send(int|string $chat, string $text, array $buttons = [], array $extra = []): ?int
    {
        try {
            $r = $this->call('sendMessage', ['chat_id' => $chat, 'text' => mb_substr($text, 0, 4000),
                'disable_web_page_preview' => true] + self::markup($buttons) + $extra);

            return $r['message_id'] ?? null;
        } catch (BotApiError $e) {
            if ($e->blocked()) {
                DB::table('bot_chats')->where('chat_id', $chat)->update(['blocked_at' => now()]);
            }
            Log::warning('bot send failed', ['chat' => $chat, 'error' => $e->getMessage()]);
            if ($e->getCode() === 429 || $e->getCode() >= 500) {
                throw $e; // let a queued job retry
            }

            return null;
        } catch (Throwable $e) {
            Log::warning('bot send failed', ['chat' => $chat, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Replace the text and buttons of a message the bot sent (button screens update in place). */
    public function edit(int|string $chat, int $messageId, string $text, array $buttons = []): void
    {
        try {
            $this->call('editMessageText', ['chat_id' => $chat, 'message_id' => $messageId, 'text' => mb_substr($text, 0, 4000),
                'disable_web_page_preview' => true] + self::markup($buttons));
        } catch (BotApiError $e) {
            if (! str_contains($e->getMessage(), 'not modified')) {
                $this->send($chat, $text, $buttons); // too old to edit: send it fresh
            }
        } catch (Throwable) {
        }
    }

    /** Stop the spinner on a tapped button, optionally with a short toast. */
    public function answer(string $callbackId, string $toast = ''): void
    {
        try {
            $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => mb_substr($toast, 0, 190)]);
        } catch (Throwable) {
        }
    }

    /** @param array<int, array<string, string>> $rows */
    public static function markup(array $rows, bool $replyKeyboard = false): array
    {
        if (! $rows) {
            return [];
        }
        if ($replyKeyboard) {
            return ['reply_markup' => ['keyboard' => array_map(fn ($row) => array_map(fn ($l) => ['text' => $l], array_values($row)), $rows),
                'resize_keyboard' => true, 'is_persistent' => true]];
        }

        return ['reply_markup' => ['inline_keyboard' => array_values(array_filter(array_map(function ($row) {
            $out = [];
            foreach ($row as $label => $v) {
                $out[] = str_starts_with((string) $v, 'https://') || str_starts_with((string) $v, 'http://')
                    ? ['text' => (string) $label, 'url' => (string) $v]
                    : ['text' => (string) $label, 'callback_data' => mb_strcut((string) $v, 0, 64)];
            }

            return $out;
        }, $rows)))]];
    }
}
