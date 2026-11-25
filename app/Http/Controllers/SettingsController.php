<?php

namespace App\Http\Controllers;

use App\Notifications\PasswordChanged;
use App\Pipstream\Accounts;
use App\Pipstream\Bot\Bot;
use App\Pipstream\Bot\BotSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class SettingsController extends Controller
{
    public function show(Request $r)
    {
        $prefs = DB::table('notification_prefs')->where('user_id', $r->user()->id)->pluck('address', 'channel');

        return ['name' => $r->user()->name, 'email' => $r->user()->email,
            'alerts' => ['telegram' => (bool) ($prefs['telegram_bot'] ?? null), 'email' => $prefs->has('email')],
            'bot_username' => BotSettings::username(), 'bot_ready' => (bool) BotSettings::token(),
            'bot' => $this->botChat($prefs['telegram_bot'] ?? null)];
    }

    private function botChat(?string $chat): ?array
    {
        $c = $chat ? DB::table('bot_chats')->where('chat_id', $chat)->first() : null;

        return $c ? ['name' => $c->first_name, 'username' => $c->username, 'trade_alerts' => (bool) $c->trade_alerts,
            'daily_summary' => (bool) $c->daily_summary, 'blocked' => (bool) $c->blocked_at] : null;
    }

    public function update(Request $r)
    {
        $d = $r->validate(['name' => ['sometimes', 'string', 'max:80'], 'email_alerts' => ['sometimes', 'boolean'],
            'telegram_alerts' => ['sometimes', 'boolean'], 'bot_trade_alerts' => ['sometimes', 'boolean'],
            'bot_daily_summary' => ['sometimes', 'boolean']]);
        $u = $r->user();
        if (isset($d['name'])) {
            $u->update(['name' => $d['name']]);
        }
        if (array_key_exists('email_alerts', $d)) {
            $d['email_alerts']
                ? DB::table('notification_prefs')->insertOrIgnore(['user_id' => $u->id, 'channel' => 'email', 'address' => $u->email])
                : DB::table('notification_prefs')->where(['user_id' => $u->id, 'channel' => 'email'])->delete();
        }
        if (($d['telegram_alerts'] ?? null) === false) {
            Bot::unlink($u);
        }
        foreach (['bot_trade_alerts' => 'trade_alerts', 'bot_daily_summary' => 'daily_summary'] as $in => $col) {
            if (array_key_exists($in, $d) && ($chat = Bot::chatOf($u))) {
                DB::table('bot_chats')->where('chat_id', $chat)->update([$col => (bool) $d[$in]]);
            }
        }

        return $this->show($r);
    }

    /** Change the password while signed in: needs the current one; emails a "password changed" note. */
    public function password(Request $r)
    {
        $d = $r->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', Password::min(8), 'different:current_password'],
        ], ['current_password.current_password' => 'That isn\'t your current password.',
            'password.different' => 'Pick a password you aren\'t using now.']);
        $u = $r->user();
        $u->forceFill(['password' => $d['password'], 'remember_token' => Str::random(60)])->save();
        $r->session()->regenerate();
        Accounts::mailNow($u, new PasswordChanged('settings'));

        return ['ok' => true];
    }

    /** One tap: opens the PipStream bot in Telegram with a one-time token; the bot's webhook
     *  links the chat to this user. */
    public function telegramAlerts(Request $r)
    {
        $token = Str::random(32);
        Cache::put("botlink:{$token}", $r->user()->id, now()->addMinutes(15));

        return ['url' => BotSettings::link($token)];
    }

    /** "Send me a test message" under Telegram alerts. */
    public function telegramAlertsTest(Request $r)
    {
        abort_unless(Bot::chatOf($r->user()), 422, 'Connect Telegram alerts first.');
        $ok = Bot::toUser($r->user(), "👋 This is a test from PipStream. Your alerts will arrive here.\n\nTap the buttons below the chat any time to check your trades.");
        abort_unless($ok, 422, 'Telegram didn\'t deliver it. Open the PipStream bot and tap Start (or unblock it), then try again.');

        return ['ok' => true];
    }
}
