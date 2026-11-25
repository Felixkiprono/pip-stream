<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcast;
use App\Models\AuditLog;
use App\Models\User;
use App\Pipstream\Bot\Bot;
use App\Pipstream\Bot\BotApi;
use App\Pipstream\Bot\BotApiError;
use App\Pipstream\Bot\BotSettings;
use App\Pipstream\Bot\BotUpdates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Admin → Telegram bot (superadmin): the @pip_stream_bot token, webhook and health check,
 * broadcasts. Admin → Support (every admin): the inbox of what customers wrote to the bot,
 * replies, and direct messages from a customer's page.
 */
class BotController extends Controller
{
    /** What the bot answers to (also set as its command menu in Telegram). */
    public const COMMANDS = [
        'start' => 'Main menu', 'status' => 'Is copying on? Is everything connected?', 'trades' => 'My open trades',
        'pnl' => 'My profit today, this week, this month', 'channels' => 'My channels: pause, change, follow',
        'pause' => 'Pause copying (no new trades)', 'resume' => 'Resume copying', 'closeall' => 'Close all open trades',
        'plan' => 'My plan and days left', 'settings' => 'Alerts and daily summary', 'test' => 'Test a signal post',
        'help' => 'Talk to the PipStream team', 'app' => 'Open the PipStream app',
    ];

    public const SEGMENTS = [
        'linked' => 'Everyone who connected the bot',
        'everyone' => 'Everyone who opened the bot (connected or not)',
        'founding' => 'Founding members',
        'trial' => 'On a free trial',
        'paid' => 'Paying customers',
        'ended' => 'Plan or trial ended',
        'inactive' => 'Not seen in the app for 7+ days',
    ];

    // ================================================================ setup (superadmin)
    public function show()
    {
        return $this->present();
    }

    private function present(?array $check = null): array
    {
        $me = request()->user();

        return BotSettings::present() + [
            'check' => $check ?? (BotSettings::token() ? Cache::remember('bot:check', 60, fn () => $this->check()) : null),
            'commands' => collect(self::COMMANDS)->map(fn ($d, $c) => ['command' => $c, 'description' => $d])->values(),
            'linked_customers' => DB::table('notification_prefs')->where('channel', 'telegram_bot')->count(),
            'me_linked' => (bool) Bot::chatOf($me),
            'me_link' => $this->startLink($me),
        ];
    }

    /** A one-tap link that connects the signed-in admin's own Telegram to the bot. */
    private function startLink(User $u): string
    {
        $token = Str::random(32);
        Cache::put("botlink:{$token}", $u->id, now()->addMinutes(15));

        return BotSettings::link($token);
    }

    /** Is the bot reachable and is Telegram delivering to our webhook? */
    private function check(): array
    {
        $api = BotApi::make();
        try {
            $me = $api->call('getMe');
            $hook = $api->call('getWebhookInfo');
        } catch (Throwable $e) {
            return ['ok' => false, 'problem' => 'Telegram refused the token: '.mb_substr($e->getMessage(), 0, 200)];
        }
        $want = BotSettings::webhookUrl();
        $problem = null;
        if (($hook['url'] ?? '') !== $want) {
            $problem = ($hook['url'] ?? '') === '' ? 'No webhook set: the bot can\'t hear customers. Tap "Connect webhook".'
                : 'The webhook points somewhere else. Tap "Connect webhook".';
        } elseif (! empty($hook['last_error_message']) && ($hook['last_error_date'] ?? 0) > time() - 900) {
            $problem = 'Telegram could not reach the site recently: '.$hook['last_error_message'];
        }

        return ['ok' => $problem === null, 'problem' => $problem, 'username' => $me['username'] ?? null, 'name' => $me['first_name'] ?? null,
            'pending' => (int) ($hook['pending_update_count'] ?? 0), 'last_error' => $hook['last_error_message'] ?? null,
            'last_error_at' => isset($hook['last_error_date']) ? date(DATE_ATOM, $hook['last_error_date']) : null, 'at' => now()->toIso8601String()];
    }

    /** Points Telegram at this site, with the secret in the URL and the header; sets the command menu. */
    private function connect(BotApi $api, string $secret): void
    {
        $api->call('setWebhook', ['url' => BotSettings::webhookUrl($secret), 'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query', 'my_chat_member'], 'drop_pending_updates' => false]);
        $api->call('setMyCommands', ['commands' => collect(self::COMMANDS)->map(fn ($d, $c) => ['command' => $c, 'description' => $d])->values()->all()]);
    }

    public function update(Request $r)
    {
        $d = $r->validate([
            'token' => ['nullable', 'string', 'max:100', 'regex:/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/'],
            'clear_token' => ['sometimes', 'boolean'],
            'ops_alerts' => ['sometimes', 'boolean'],
        ], ['token.regex' => 'That isn\'t a bot token. It looks like 123456789:AAE… and comes from @BotFather.']);
        $s = BotSettings::stored();
        if (! empty($d['clear_token'])) {
            rescue(fn () => BotApi::make()->call('deleteWebhook'), null, false);
            unset($s['token'], $s['username'], $s['secret']);
        } elseif (! empty($d['token'])) {
            $api = new BotApi(trim($d['token']));
            try {
                $me = $api->call('getMe');
                $secret = BotSettings::newSecret();
                $this->connect($api, $secret);
            } catch (BotApiError $e) {
                abort(422, 'Telegram refused it: '.$e->getMessage());
            } catch (Throwable $e) {
                abort(422, 'Couldn\'t reach Telegram: '.mb_substr($e->getMessage(), 0, 200));
            }
            $s = array_merge($s, ['token' => trim($d['token']), 'username' => $me['username'] ?? BotSettings::username(), 'secret' => $secret]);
        }
        if (array_key_exists('ops_alerts', $d)) {
            $s['ops_alerts'] = (bool) $d['ops_alerts'];
        }
        BotSettings::save($s);
        Cache::forget('bot:check');
        AuditLog::record($r->user()->id, 'bot_settings_changed', 'platform_setting', BotSettings::KEY,
            ['token_changed' => ! empty($d['token']) || ! empty($d['clear_token']), 'ops_alerts' => $s['ops_alerts'] ?? null]);

        return $this->present();
    }

    /** "Connect webhook": (re)points Telegram at this site, e.g. after the domain changed. */
    public function webhook(Request $r)
    {
        abort_unless(BotSettings::token(), 422, 'Add the bot token first.');
        $secret = BotSettings::secret() ?: BotSettings::newSecret();
        try {
            $this->connect(BotApi::make(), $secret);
        } catch (Throwable $e) {
            abort(422, 'Telegram refused it: '.mb_substr($e->getMessage(), 0, 200));
        }
        if (BotSettings::source() === 'admin' && (BotSettings::stored()['secret'] ?? null) !== $secret) {
            BotSettings::save(array_merge(BotSettings::stored(), ['secret' => $secret]));
        }
        Cache::forget('bot:check');
        AuditLog::record($r->user()->id, 'bot_webhook_set', 'platform_setting', BotSettings::KEY);

        return $this->present($this->check());
    }

    /** A test message to the signed-in admin's own chat. */
    public function test(Request $r)
    {
        $chat = Bot::chatOf($r->user());
        abort_unless($chat, 422, 'Connect your own Telegram to the bot first (the button above).');
        $ok = BotApi::make()->send($chat, "✅ Test from PipStream Admin.\nThe bot can reach you. Customers get their alerts the same way.");
        abort_unless($ok !== null, 422, 'Telegram didn\'t deliver it. Did you block the bot?');

        return ['ok' => true];
    }

    // ================================================================ broadcasts (superadmin)
    private function audience(string $segment): \Illuminate\Support\Collection
    {
        $q = DB::table('bot_chats')->whereNull('blocked_at');
        if ($segment !== 'everyone') {
            $q->join('users', 'users.id', '=', 'bot_chats.user_id')->where('users.status', 'active');
            $billing = fn () => $q->join('billing_subscriptions', 'billing_subscriptions.user_id', '=', 'users.id');
            match ($segment) {
                'founding' => $q->whereNotNull('users.founding_number'),
                'trial' => $billing()->where('billing_subscriptions.status', 'trialing'),
                'paid' => $billing()->join('plans', 'plans.id', '=', 'billing_subscriptions.plan_id')
                    ->where('billing_subscriptions.status', 'active')->where('plans.price_cents', '>', 0),
                'ended' => $billing()->where('billing_subscriptions.plan_id', '<>', 'lifetime')->whereNotNull('billing_subscriptions.current_period_end')
                    ->where('billing_subscriptions.current_period_end', '<', now()),
                'inactive' => $q->where(fn ($w) => $w->whereNull('users.last_seen_at')->orWhere('users.last_seen_at', '<', now()->subDays(7))),
                default => null,
            };
        }

        return $q->get(['bot_chats.chat_id', 'bot_chats.user_id'])->unique('chat_id')->values();
    }

    public function segments()
    {
        return ['segments' => collect(self::SEGMENTS)->map(fn ($label, $id) => ['id' => $id, 'label' => $label, 'count' => $this->audience($id)->count()])->values(),
            'broadcasts' => DB::table('bot_broadcasts')->orderByDesc('id')->limit(20)->get()];
    }

    public function broadcast(Request $r)
    {
        $d = $r->validate(['segment' => ['required', 'in:'.implode(',', array_keys(self::SEGMENTS))],
            'text' => ['required', 'string', 'min:3', 'max:3500'], 'test' => ['sometimes', 'boolean']]);
        abort_unless(BotSettings::token(), 422, 'Set up the bot first.');
        if (! empty($d['test'])) {   // preview in the admin's own chat
            $chat = Bot::chatOf($r->user());
            abort_unless($chat, 422, 'Connect your own Telegram to the bot first, so you can preview.');
            BotApi::make()->send($chat, $d['text']);

            return ['ok' => true, 'test' => true];
        }
        $to = $this->audience($d['segment']);
        abort_if($to->isEmpty(), 422, 'Nobody in that group has the bot yet.');
        abort_unless(\Illuminate\Support\Facades\RateLimiter::attempt('bot-broadcast', 5, fn () => true, 3600), 429, 'That\'s a lot of broadcasts. Try again in an hour.');
        $id = DB::table('bot_broadcasts')->insertGetId(['admin_id' => $r->user()->id, 'segment' => $d['segment'], 'text' => $d['text'],
            'total' => $to->count(), 'created_at' => now()]);
        AuditLog::record($r->user()->id, 'bot_broadcast', 'bot_broadcast', (string) $id, ['segment' => $d['segment'], 'total' => $to->count()]);
        SendBroadcast::dispatch($id, $to->map(fn ($x) => ['chat_id' => (int) $x->chat_id, 'user_id' => $x->user_id])->all());

        return ['ok' => true, 'broadcast' => DB::table('bot_broadcasts')->find($id)];
    }

    // ================================================================ support inbox (admins)
    public function inbox(Request $r)
    {
        $last = DB::table('bot_messages')->selectRaw('chat_id, max(id) as last_id, count(*) filter (where direction = \'in\' and read_at is null) as unread')
            ->groupBy('chat_id');
        $rows = DB::table('bot_messages as m')->joinSub($last, 'l', 'l.last_id', '=', 'm.id')
            ->leftJoin('bot_chats as c', 'c.chat_id', '=', 'm.chat_id')->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->whereExists(fn ($q) => $q->from('bot_messages as x')->whereColumn('x.chat_id', 'm.chat_id')->where('x.direction', 'in'))
            ->orderByDesc('m.id')->limit(100)
            ->get(['m.chat_id', 'm.text', 'm.direction', 'm.created_at', 'l.unread', 'c.first_name', 'c.username', 'c.user_id', 'u.name', 'u.email']);

        return ['conversations' => $rows, 'unread' => (int) DB::table('bot_messages')->where('direction', 'in')->whereNull('read_at')->count()];
    }

    public function conversation(Request $r, int $chat)
    {
        $c = DB::table('bot_chats')->where('chat_id', $chat)->first();
        abort_unless($c, 404);
        DB::table('bot_messages')->where('chat_id', $chat)->where('direction', 'in')->whereNull('read_at')->update(['read_at' => now()]);
        $u = $c->user_id ? User::find($c->user_id) : null;

        return ['chat' => ['chat_id' => $c->chat_id, 'first_name' => $c->first_name, 'username' => $c->username, 'blocked' => (bool) $c->blocked_at,
            'user' => $u ? ['id' => $u->id, 'name' => $u->name, 'email' => $u->email] : null],
            'messages' => DB::table('bot_messages')->where('chat_id', $chat)->orderByDesc('id')->limit(200)->get()->reverse()->values()];
    }

    public function reply(Request $r, int $chat)
    {
        $text = $r->validate(['text' => ['required', 'string', 'max:3500']])['text'];
        $c = DB::table('bot_chats')->where('chat_id', $chat)->first();
        abort_unless($c, 404);

        return $this->deliver($r, $chat, $c->user_id, $text, 'reply');
    }

    /** From a customer's page in Admin: a direct message to their bot chat. */
    public function message(Request $r, User $user)
    {
        $text = $r->validate(['text' => ['required', 'string', 'max:3500']])['text'];
        $chat = Bot::chatOf($user);
        abort_unless($chat, 422, 'This customer hasn\'t connected the PipStream bot.');

        return $this->deliver($r, $chat, $user->id, $text, 'direct');
    }

    private function deliver(Request $r, int $chat, ?string $userId, string $text, string $kind)
    {
        abort_unless(BotSettings::token(), 422, 'Set up the bot first (Admin → Telegram bot).');
        $ok = BotApi::make()->send($chat, "💬 PipStream team:\n{$text}", [['↩️ Reply' => 'm:help']]);
        abort_unless($ok !== null, 422, 'Telegram didn\'t deliver it (the customer may have blocked the bot).');
        BotUpdates::store($chat, $userId, 'out', $kind, $text, $r->user()->id);
        AuditLog::record($r->user()->id, 'bot_message_sent', 'user', $userId, ['kind' => $kind]);

        return ['ok' => true];
    }
}
