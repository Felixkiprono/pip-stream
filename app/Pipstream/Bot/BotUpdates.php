<?php

namespace App\Pipstream\Bot;

use App\Models\AuditLog;
use App\Models\BrokerAccount;
use App\Models\Channel;
use App\Models\CopyRule;
use App\Models\User;
use App\Notifications\BotLinkCode;
use App\Pipstream\Accounts;
use App\Pipstream\Bus;
use App\Pipstream\Ids;
use App\Pipstream\KillSwitch;
use App\Pipstream\LiveGate;
use App\Pipstream\Presets;
use App\Pipstream\TelegramLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Everything a customer sends to @pip_stream_bot (one Telegram update at a time, from the
 * webhook). Typed commands work, but the bot leads with buttons: the reply keyboard at the
 * bottom of the chat and buttons under each message.
 *
 * Without the web app a customer can still: link their account (by email code), see status,
 * trades and profit, pause and resume, close all trades, pause, restyle, follow and unfollow
 * channels, check their plan, test a post and talk to support. Anything bigger links to the app.
 */
class BotUpdates
{
    private BotApi $api;

    public function __construct(private BotScreens $screens, private KillSwitch $kill, private Bus $bus,
        private Accounts $accounts, private TelegramLink $tg)
    {
        $this->api = BotApi::make();
    }

    public function handle(array $u): void
    {
        if (isset($u['callback_query'])) {
            $this->callback($u['callback_query']);
        } elseif (isset($u['message'])) {
            $this->message($u['message']);
        } elseif (isset($u['my_chat_member'])) {
            $m = $u['my_chat_member'];
            if (($m['chat']['type'] ?? '') === 'private') {
                $blocked = in_array($m['new_chat_member']['status'] ?? '', ['kicked', 'left'], true);
                DB::table('bot_chats')->where('chat_id', $m['chat']['id'])->update(['blocked_at' => $blocked ? now() : null]);
            }
        }
    }

    // ================================================================ messages
    private function message(array $m): void
    {
        $chatId = (int) ($m['chat']['id'] ?? 0);
        if (! $chatId || ($m['chat']['type'] ?? '') !== 'private') {
            return; // the bot only works in a one-to-one chat
        }
        $chat = Bot::touch($m['from'] ?? [], $chatId);
        $user = Bot::userOfChat($chatId);
        $text = trim((string) ($m['text'] ?? $m['caption'] ?? ''));

        // a forwarded channel post: show how PipStream reads it
        if (isset($m['forward_origin']) || isset($m['forward_from_chat'])) {
            $this->forwarded($chatId, $user, $m, $text);

            return;
        }
        if (preg_match('/^\/start(?:@\w+)?(?:\s+(\S+))?$/', $text, $s)) {
            $this->start($chatId, $chat, $user, $s[1] ?? null);

            return;
        }
        // a step-by-step flow in progress (link by email)
        $state = $chat->state ? json_decode($chat->state, true) : null;
        if ($state && ! $this->isMenu($text)) {
            if ($this->flow($chatId, $state, $text, $m['from'] ?? [])) {
                return;
            }
        }
        if ($action = $this->action($text)) {
            if (! $user) {
                $this->welcome($chatId, $m['from']['first_name'] ?? null);

                return;
            }
            $this->clearState($chatId);
            $this->screen($chatId, $user, $action);

            return;
        }
        if ($text === '' && ! isset($m['photo']) && ! isset($m['document'])) {
            return;
        }
        $this->support($chatId, $user, $text !== '' ? $text : '[sent a photo or file]', $m['from']['first_name'] ?? null);
    }

    private function start(int $chatId, object $chat, ?User $user, ?string $token): void
    {
        $this->clearState($chatId);
        if ($token && preg_match('/^[A-Za-z0-9]{32}$/', $token) && ($uid = Cache::pull("botlink:{$token}")) && ($nu = User::find($uid))) {
            Bot::link($chatId, $nu, 'app');
            $this->linked($chatId, $nu);

            return;
        }
        if ($user) {
            [$text] = $this->screens->menu($user);
            $this->api->send($chatId, $text, [], $this->screens->keyboard($user));

            return;
        }
        $this->welcome($chatId, $chat->first_name);
    }

    private function welcome(int $chatId, ?string $name): void
    {
        [$text, $buttons] = $this->screens->welcome($name);
        $this->api->send($chatId, $text, $buttons);
    }

    private function linked(int $chatId, User $u): void
    {
        $first = trim(explode(' ', (string) $u->name)[0] ?? '');
        $this->api->send($chatId, '✅ You\'re connected'.($first ? ", {$first}" : '').'! This chat is now your official PipStream line.'."\n\n"
            ."I'll message you when trades open, change or close, and if anything needs your attention.\n\n"
            .'Use the buttons at the bottom to check your trades or pause copying.', [], $this->screens->keyboard($u));
    }

    private function isMenu(string $text): bool
    {
        return $text !== '' && ($text[0] === '/' || in_array($text, BotScreens::MENU, true));
    }

    /** A typed command or a reply-keyboard button → a screen name. */
    private function action(string $text): ?string
    {
        if ($text === '') {
            return null;
        }
        $key = array_search($text, BotScreens::MENU, true);
        if ($key !== false) {
            return $key;
        }
        if (preg_match('/^\/([a-z_]+)(?:@\w+)?\b/i', $text, $m)) {
            $cmd = strtolower($m[1]);

            return ['menu' => 'menu', 'status' => 'status', 'trades' => 'trades', 'pnl' => 'pnl', 'profit' => 'pnl',
                'channels' => 'channels', 'pause' => 'pause', 'resume' => 'resume', 'closeall' => 'closeall',
                'plan' => 'plan', 'settings' => 'settings', 'help' => 'help', 'app' => 'app', 'test' => 'test',
                'follow' => 'follow'][$cmd] ?? 'unknown';
        }

        return null;
    }

    /** Show a screen as a new message (from a command or the keyboard). */
    private function screen(int $chatId, User $u, string $name): void
    {
        if ($name === 'pause' || $name === 'resume') {
            $this->pause($chatId, $u, $name === 'pause'); // its own message, with the keyboard refreshed

            return;
        }
        [$text, $buttons] = $this->render($chatId, $u, $name);
        $this->api->send($chatId, $text, $buttons);
    }

    private function render(int $chatId, User $u, string $name): array
    {
        return match ($name) {
            'status' => $this->screens->status($u),
            'trades' => $this->screens->trades($u),
            'closed' => $this->screens->closed($u),
            'pnl' => $this->screens->pnl($u),
            'channels' => $this->screens->channels($u),
            'plan' => $this->screens->plan($u),
            'settings' => $this->screens->settings($u, Bot::chat($chatId)),
            'help' => $this->screens->help(),
            'test' => $this->screens->test(),
            'menu' => $this->screens->menu($u),
            'pause' => $this->pause($chatId, $u, true),
            'resume' => $this->pause($chatId, $u, false),
            'closeall' => $this->closeAllAsk($u),
            'follow' => $this->followList($chatId, $u),
            'app' => ['🌐 Open PipStream for everything else: brokers, real-money accounts, charts and more.', [['🌐 Open PipStream' => Bot::app()]]],
            default => ["I didn't catch that. Tap a button below, or type your question and our team will reply.", []],
        };
    }

    // ================================================================ buttons
    private function callback(array $q): void
    {
        $chatId = (int) ($q['message']['chat']['id'] ?? 0);
        $msgId = (int) ($q['message']['message_id'] ?? 0);
        $data = (string) ($q['data'] ?? '');
        if (! $chatId) {
            $this->api->answer($q['id']);

            return;
        }
        Bot::touch($q['from'] ?? [], $chatId);
        $user = Bot::userOfChat($chatId);
        if ($data === 'link') {
            $this->api->answer($q['id']);
            $this->askEmail($chatId);

            return;
        }
        if ($data === 'about') {
            $this->api->answer($q['id']);
            [$t, $b] = $this->screens->about();
            $this->api->send($chatId, $t, $b);

            return;
        }
        if (! $user) {
            $this->clearState($chatId);
            $this->api->answer($q['id'], 'Connect your PipStream account first.');
            $this->welcome($chatId, $q['from']['first_name'] ?? null);

            return;
        }
        [$verb, $a, $b] = array_pad(explode(':', $data, 3), 3, null);
        $toast = '';
        $out = match ($verb) {
            'm' => $this->render($chatId, $user, (string) $a),
            'p' => $this->pause($chatId, $user, $a === '1'),
            'ca' => match ($a) {
                'yes' => $this->closeAll($user),
                'no' => ['👍 OK, your trades stay open.', [['📈 My trades' => 'm:trades']]],
                default => $this->closeAllAsk($user),
            },
            'ch' => $this->channelScreen($user, (string) $a),
            'ce' => $this->channelEnable($user, (string) $a, $b === '1', $toast),
            'cs' => $this->channelStyle($user, (string) $a, (string) $b, $toast),
            'cq' => $this->channelAsk($chatId, $user, (string) $a, $b === '1', $toast),
            'cu' => $this->unfollowAsk($user, (string) $a),
            'cux' => $this->unfollow($user, (string) $a),
            'fl' => $this->follow($chatId, $user, (string) $a),
            'cf' => $this->confirmSignal($user, (string) $a, (string) $b),
            'lv' => $this->confirmLive($user, (string) $a, (string) $b),
            'st' => $this->setting($chatId, $user, (string) $a, (string) $b),
            'sec' => $this->lockAccount($user),
            'rp' => $this->report($chatId, $user),
            default => null,
        };
        $this->api->answer($q['id'], $toast);
        if ($out) {
            [$text, $buttons] = $out;
            $msgId ? $this->api->edit($chatId, $msgId, $text, $buttons) : $this->api->send($chatId, $text, $buttons);
        }
    }

    // ================================================================ actions
    private function pause(int $chatId, User $u, bool $on): array
    {
        $this->kill->set($on, $u->id, $u->id);
        $u->forceFill(['kill_switch' => $on])->save();
        // the reply keyboard shows Pause or Resume: refresh it
        $this->api->send($chatId, $on
            ? "⏸ Copying is paused. No new trades will be opened.\n\nTrades that are already open stay open. Tap Resume copying when you're ready."
            : '▶️ Copying is back on. New signals from your channels will be copied again.', [], $this->screens->keyboard($u->fresh()));

        return $this->screens->status($u->fresh());
    }

    private function openCount(User $u): int
    {
        return DB::table('positions')->where('user_id', $u->id)->where('status', 'open')->count();
    }

    private function closeAllAsk(User $u): array
    {
        $n = $this->openCount($u);
        if (! $n) {
            return ['📈 You have no open trades, so there is nothing to close.', [['📈 My trades' => 'm:trades']]];
        }
        $real = DB::table('positions')->join('broker_accounts', 'broker_accounts.id', '=', 'positions.broker_account_id')
            ->where('positions.user_id', $u->id)->where('positions.status', 'open')->where('broker_accounts.connector', '<>', 'paper')->count();

        return ["🛑 Close all {$n} open trade".($n === 1 ? '' : 's')." now?\n\n"
            .'They close at the current market price'.($real ? ", including {$real} in your broker account (real or demo)" : '').'. This can\'t be undone.',
            [['✅ Yes, close all' => 'ca:yes', '✖ No, keep them' => 'ca:no']]];
    }

    private function closeAll(User $u): array
    {
        $n = $this->openCount($u);
        if (! $n) {
            return ['📈 There are no open trades left to close.', []];
        }
        if (! Cache::add("botcloseall:{$u->id}", 1, 30)) {
            return ['⏳ Already closing your trades. I\'ll message you as each one closes.', []];
        }
        try {
            $this->bus->publish('user.command', ['command_id' => Ids::new('cmd'), 'user_id' => $u->id, 'action' => 'close_all']);
        } catch (Throwable) {
            Cache::forget("botcloseall:{$u->id}");

            return ['⚠️ I couldn\'t reach the trading engine just now. Please try again in a minute, or close the trades in MetaTrader.', [['🔁 Try again' => 'ca:yes']]];
        }
        AuditLog::record($u->id, 'close_all_requested', 'user', $u->id, ['via' => 'bot', 'open' => $n]);

        return ["⏳ Closing {$n} trade".($n === 1 ? '' : 's')."… I'll message you as each one closes.", [['📈 My trades' => 'm:trades']]];
    }

    private function channelOf(User $u, string $id): ?Channel
    {
        $c = Channel::find($id);

        return $c && DB::table('subscriptions')->where(['user_id' => $u->id, 'channel_id' => $c->id])->exists() ? $c : null;
    }

    private function channelScreen(User $u, string $id): array
    {
        $c = $this->channelOf($u, $id);

        return $c ? $this->screens->channel($u, $c) : $this->screens->channels($u);
    }

    private function rule(User $u, Channel $c): ?CopyRule
    {
        return CopyRule::where('user_id', $u->id)->where('channel_id', $c->id)->first();
    }

    private function changed(CopyRule $r): void
    {
        rescue(fn () => $this->bus->publish('config.changed', ['entity' => 'copy_rule', 'entity_id' => $r->id]), null, false);
    }

    private function channelEnable(User $u, string $id, bool $on, string &$toast): array
    {
        if (($c = $this->channelOf($u, $id)) && ($r = $this->rule($u, $c))) {
            $r->update(['enabled' => $on]);
            $this->changed($r);
            AuditLog::record($u->id, $on ? 'rule_enabled' : 'rule_disabled', 'copy_rule', $r->id, ['via' => 'bot']);
            $toast = $on ? 'Copying this channel again' : 'Channel paused';

            return $this->screens->channel($u, $c);
        }

        return $this->screens->channels($u);
    }

    /** Careful / Balanced / Bold from the bot: only the size changes, the account and mode stay. */
    private function channelStyle(User $u, string $id, string $style, string &$toast): array
    {
        if (! isset(Presets::STYLES[$style]) || ! ($c = $this->channelOf($u, $id)) || ! ($r = $this->rule($u, $c))) {
            return $this->screens->channels($u);
        }
        $keep = array_intersect_key((array) $r->limits, array_flip(['max_daily_loss', 'confirm_first', 'duplicate_window_min']));
        $p = Presets::apply($style);
        $r->fill(['sizing_mode' => $p['sizing_mode'], 'risk_pct' => $p['risk_pct'], 'fixed_lots' => $p['fixed_lots'],
            'max_lot' => $p['max_lot'], 'limits' => $p['limits'] + $keep])->save();
        $this->changed($r);
        AuditLog::record($u->id, 'rule_style', 'copy_rule', $r->id, ['via' => 'bot', 'style' => $style]);
        $toast = 'Style: '.Presets::STYLES[$style]['label'];

        return $this->screens->channel($u, $c);
    }

    private function channelAsk(int $chatId, User $u, string $id, bool $on, string &$toast): array
    {
        if (($c = $this->channelOf($u, $id)) && ($r = $this->rule($u, $c))) {
            $r->update(['limits' => array_merge((array) $r->limits, ['confirm_first' => $on])]);
            $this->changed($r);
            AuditLog::record($u->id, 'rule_confirm_first', 'copy_rule', $r->id, ['via' => 'bot', 'on' => $on]);
            $toast = $on ? 'I\'ll ask you before each trade' : 'Trades copy straight away';
            if ($on) {
                $this->api->send($chatId, '🙋 From now on, when '.BotScreens::title($c)." posts a new signal I'll ask you first. Tap Copy it within 5 minutes, or it is skipped.");
            }

            return $this->screens->channel($u, $c);
        }

        return $this->screens->channels($u);
    }

    private function unfollowAsk(User $u, string $id): array
    {
        $c = $this->channelOf($u, $id);
        if (! $c) {
            return $this->screens->channels($u);
        }

        return ['🗑 Stop following '.BotScreens::title($c)."?\n\nNew signals from it won't be copied any more. Trades that are already open stay open.",
            [['✅ Yes, stop following' => "cux:{$c->id}", '✖ Keep it' => "ch:{$c->id}"]]];
    }

    private function unfollow(User $u, string $id): array
    {
        if ($c = $this->channelOf($u, $id)) {
            $this->accounts->unfollow($u, $c);
            AuditLog::record($u->id, 'channel_unfollowed', 'channel', $c->id, ['via' => 'bot']);
            [$t, $b] = $this->screens->channels($u);

            return ['✅ You no longer follow '.BotScreens::title($c).".\n\n".$t, $b];
        }

        return $this->screens->channels($u);
    }

    /** The channels in the customer's own Telegram that they don't follow yet, as buttons. */
    private function followList(int $chatId, User $u): array
    {
        $acc = $u->telegramAccounts()->where('status', 'active')->whereNotNull('session_ciphertext')->orderByDesc('created_at')->first();
        if (! $acc) {
            return ["➕ To follow channels, PipStream reads them through your own Telegram account.\n\n"
                .'That is a one-time setup in the app and takes about 2 minutes. Come back here when it\'s done.',
                [['🔗 Connect Telegram in the app' => Bot::app('/start/telegram')]]];
        }
        $list = TelegramLink::cachedChannels($acc->id);
        if ($list === null) {
            $this->api->send($chatId, '⏳ Looking at the channels in your Telegram…');
            $res = rescue(fn () => $this->tg->request($acc, 'list_channels'), [], false);
            $list = $res['channels'] ?? TelegramLink::cachedChannels($acc->id);
        }
        if ($list === null) {
            return ['⚠️ Telegram is slow to answer right now. Please try again in a minute.', [['🔁 Try again' => 'm:follow']]];
        }
        $followed = DB::table('subscriptions')->join('channels', 'channels.id', '=', 'subscriptions.channel_id')
            ->where('subscriptions.user_id', $u->id)->pluck('channels.tg_chat_id')->map(fn ($x) => (int) $x)->all();
        $todo = array_values(array_filter($list, fn ($c) => ! in_array((int) $c['tg_chat_id'], $followed, true)));
        if (! $todo) {
            return ["You already follow every channel in your Telegram.\n\nJoin a new signal channel in Telegram first, then tap Follow a channel again.",
                [['🔄 Check again' => 'm:follow'], ['📡 My channels' => 'm:channels']]];
        }
        $buttons = [];
        foreach (array_slice($todo, 0, 15) as $c) {
            $buttons[] = ['➕ '.BotScreens::short((string) ($c['title'] ?? $c['username'] ?? 'Channel'), 40) => 'fl:'.$c['tg_chat_id']];
        }
        $buttons[] = ['⬅️ Back' => 'm:channels'];

        return ["➕ Which channel should PipStream copy?\n\nTap one. Its signals go to your free practice account first, with the Balanced style. You can change that any time.", $buttons];
    }

    private function follow(int $chatId, User $u, string $tgChatId): array
    {
        $acc = $u->telegramAccounts()->where('status', 'active')->whereNotNull('session_ciphertext')->orderByDesc('created_at')->first();
        $found = $acc ? collect(TelegramLink::cachedChannels($acc->id) ?? [])->first(fn ($c) => (string) $c['tg_chat_id'] === $tgChatId) : null;
        if (! $acc || ! $found) {
            return $this->followList($chatId, $u);
        }
        $c = $this->accounts->follow($u, $acc, (int) $found['tg_chat_id'], $found['title'] ?? null, $found['username'] ?? null);

        return ['✅ Now following '.BotScreens::title($c).".\n\nNew signals are copied to your practice account with the Balanced style. Tap below to change it.",
            [['⚙️ '.BotScreens::short(BotScreens::title($c)) => 'ch:'.$c->id], ['➕ Follow another' => 'm:follow', '📡 My channels' => 'm:channels']]];
    }

    /** "Ask me before each trade": the engine holds the signal until the customer answers. */
    private function confirmSignal(User $u, string $yes, string $pendingId): array
    {
        $owner = Cache::get("botpending:{$pendingId}");
        if ($owner !== $u->id) {
            return ['⌛ This signal has expired, so it was not copied.', []];
        }
        Cache::forget("botpending:{$pendingId}");
        $ok = $yes === 'y';
        try {
            $this->bus->publish('user.command', ['command_id' => Ids::new('cmd'), 'user_id' => $u->id,
                'action' => $ok ? 'approve_signal' : 'reject_signal', 'pending_id' => $pendingId]);
        } catch (Throwable) {
            return ['⚠️ I couldn\'t reach the trading engine, so this signal was not copied.', []];
        }
        $what = Cache::pull("botpendingtext:{$pendingId}") ?? 'the signal';

        return $ok ? ["✅ Copying {$what}. I'll message you when it opens.", []] : ["✖ Skipped {$what}. Nothing was copied.", []];
    }

    /** "Yes, copy into this real-money account" from the bot, for the login MetaTrader is on now. */
    private function confirmLive(User $u, string $yes, string $brokerId): array
    {
        $b = BrokerAccount::where('id', $brokerId)->where('user_id', $u->id)->where('connector', 'ea_bridge')->where('enabled', true)->first();
        if (! $b) {
            return ['That account is no longer connected.', []];
        }
        if ($yes !== 'y') {
            return ["👍 OK. PipStream won't open trades in this real-money account. You can say yes later in the app (Brokers).", [['🌐 Open Brokers' => Bot::app('/app/brokers')]]];
        }
        $state = LiveGate::state($b);
        if (LiveGate::kind($state) !== 'real') {
            return ['⚠️ MetaTrader needs to be open with PipStream connected to confirm. Open it and try again.', [['🔁 Try again' => "lv:y:{$b->id}"]]];
        }
        LiveGate::confirm($b, $state, $u->id);

        return ['✅ Confirmed. PipStream will copy into '.($b->label ?: 'this account').' (login '.($state['login'] ?? '?').') with real money.', []];
    }

    private function setting(int $chatId, User $u, string $what, string $on): array
    {
        if ($what === 'trade' || $what === 'daily') {
            DB::table('bot_chats')->where('chat_id', $chatId)->update([$what === 'trade' ? 'trade_alerts' : 'daily_summary' => $on === '1']);

            return $this->screens->settings($u, Bot::chat($chatId));
        }
        if ($what === 'unlink') {
            return ["🔌 Disconnect this chat from {$u->email}?\n\nYou won't get alerts here any more. You can connect again any time.",
                [['✅ Yes, disconnect' => 'st:unlinkx', '✖ No' => 'm:settings']]];
        }
        if ($what === 'unlinkx') {
            Bot::unlink($u);
            AuditLog::record($u->id, 'bot_unlinked', 'user', $u->id, ['via' => 'bot']);
            $this->api->send($chatId, '🔌 Disconnected. Tap /start if you want to connect again.', [], ['reply_markup' => ['remove_keyboard' => true]]);

            return ['Disconnected.', []];
        }

        return $this->screens->settings($u, Bot::chat($chatId));
    }

    /** "It wasn't me" under a new sign-in notice: sign out every device and point to a reset. */
    private function lockAccount(User $u): array
    {
        DB::table('sessions')->where('user_id', $u->id)->delete();
        $u->forceFill(['remember_token' => \Illuminate\Support\Str::random(60)])->save();
        AuditLog::record($u->id, 'signed_out_everywhere', 'user', $u->id, ['via' => 'bot']);

        return ["🔒 Done. Your account is signed out on every device.\n\nNow choose a new password so nobody else can sign in.",
            [['🔑 Choose a new password' => Bot::app('/forgot-password')]]];
    }

    // ================================================================ link by email
    private function askEmail(int $chatId): void
    {
        $this->setState($chatId, ['step' => 'email']);
        $this->api->send($chatId, "🔗 Let's connect your account.\n\nType the email address you use for PipStream:");
    }

    /** Returns true when the message belonged to the flow. */
    private function flow(int $chatId, array $state, string $text, array $from): bool
    {
        if (($state['step'] ?? null) === 'email') {
            if (! filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $this->api->send($chatId, 'That doesn\'t look like an email address. Please type it again, like name@example.com', [['✖ Cancel' => 'm:menu']]);

                return true;
            }
            if (! \Illuminate\Support\Facades\RateLimiter::attempt("botlinkmail:{$chatId}", 5, fn () => true, 3600)) {
                $this->api->send($chatId, 'Too many tries. Please wait an hour, or connect from the app: Settings → Telegram alerts.', [['🌐 Open PipStream' => Bot::app('/app/settings')]]);
                $this->clearState($chatId);

                return true;
            }
            $email = strtolower($text);
            $u = User::where('email', $email)->first();
            $code = (string) random_int(100000, 999999);
            if ($u) {
                Cache::put("botcode:{$chatId}", ['user_id' => $u->id, 'hash' => hash('sha256', $code), 'tries' => 0], now()->addMinutes(15));
                Accounts::mailNow($u, new BotLinkCode($code));
            }
            $this->setState($chatId, ['step' => 'code', 'email' => $email]);
            // the same answer whether or not the email has an account
            $this->api->send($chatId, "📧 If {$email} has a PipStream account, I just emailed it a 6-digit code.\n\nType the code here (check spam if you don't see it):",
                [['↩️ Use another email' => 'link']]);

            return true;
        }
        if (($state['step'] ?? null) === 'code') {
            $digits = preg_replace('/\D/', '', $text);
            $c = Cache::get("botcode:{$chatId}");
            if (strlen($digits) !== 6) {
                $this->api->send($chatId, 'Please type the 6-digit code from the email.', [['↩️ Use another email' => 'link']]);

                return true;
            }
            if (! $c || $c['tries'] >= 5 || ! hash_equals($c['hash'], hash('sha256', $digits))) {
                if ($c) {
                    $c['tries']++;
                    Cache::put("botcode:{$chatId}", $c, now()->addMinutes(15));
                }
                $left = $c ? max(0, 5 - $c['tries']) : 0;
                $this->api->send($chatId, $left > 0 ? "❌ That code isn't right. You have {$left} more tr".($left === 1 ? 'y' : 'ies').'.' : '❌ That code doesn\'t work. Tap below to get a new one.',
                    [['↩️ Send a new code' => 'link']]);
                if (! $left) {
                    Cache::forget("botcode:{$chatId}");
                    $this->clearState($chatId);
                }

                return true;
            }
            Cache::forget("botcode:{$chatId}");
            $u = User::find($c['user_id']);
            if ($u) {
                Bot::link($chatId, $u, 'email_code');
                $this->linked($chatId, $u);
            }

            return true;
        }
        $this->clearState($chatId);

        return false;
    }

    private function setState(int $chatId, ?array $state): void
    {
        DB::table('bot_chats')->where('chat_id', $chatId)->update(['state' => $state ? json_encode($state) : null]);
    }

    private function clearState(int $chatId): void
    {
        $this->setState($chatId, null);
    }

    // ================================================================ forwarded post
    private function forwarded(int $chatId, ?User $user, array $m, string $text): void
    {
        $origin = $m['forward_origin']['chat'] ?? $m['forward_from_chat'] ?? null;
        $title = $origin['title'] ?? ($m['forward_origin']['sender_user_name'] ?? null);
        if ($text === '') {
            $this->api->send($chatId, '🧪 That post has no text, so there is nothing for PipStream to read. Signals need to be written out (a picture alone isn\'t read).');

            return;
        }
        $channel = $origin && isset($origin['id']) ? Channel::where('tg_chat_id', (int) $origin['id'])->first() : null;
        try {
            $res = Http::timeout(6)->post(rtrim(config('pipstream.engine_url'), '/').'/parse-preview',
                array_filter(['text' => mb_substr($text, 0, 4000), 'channel_id' => $channel?->id]))->throw()->json();
        } catch (Throwable) {
            $this->api->send($chatId, '⚠️ The signal reader is busy right now. Please forward the post again in a minute.');

            return;
        }
        Cache::put("botreport:{$chatId}", ['text' => mb_substr($text, 0, 2000), 'channel' => $title, 'result' => $res], now()->addHour());
        $from = $title ? " from {$title}" : '';
        if (($res['status'] ?? null) === 'parsed') {
            $s = $res['signal'];
            $lines = ["🧪 Here's how PipStream reads this post{$from}:", '', '✅ '.BotScreens::signalLine($s)];
            if (isset($s['confidence'])) {
                $lines[] = 'Sure about it: '.round(100 * (float) $s['confidence']).'%';
            }
            $follows = $user && $channel && DB::table('subscriptions')->where(['user_id' => $user->id, 'channel_id' => $channel->id])->exists();
            $lines[] = '';
            $lines[] = $follows ? 'You follow this channel, so a post like this is copied with your settings.'
                : ($user ? 'You don\'t follow this channel yet.' : 'Connect your account to copy channels like this one.');
            $buttons = [];
            if ($user && ! $follows && $origin && isset($origin['id'])) {
                $buttons[] = ['➕ Follow this channel' => 'm:follow'];
            }
            $buttons[] = ['🚩 That\'s wrong, tell the team' => 'rp'];
            $this->api->send($chatId, implode("\n", $lines), $buttons);

            return;
        }
        $why = str_replace('_', ' ', (string) ($res['reason'] ?? 'not a signal'));
        $this->api->send($chatId, "🤔 PipStream doesn't see a trade signal in this post{$from} ({$why}), so nothing would be copied.\n\n"
            .'If it is a signal, tap below and our team will teach PipStream to read it.', [['🚩 It is a signal, tell the team' => 'rp']]);
    }

    private function report(int $chatId, User $u): array
    {
        $r = Cache::pull("botreport:{$chatId}");
        if (! $r) {
            return ['Please forward the post again, then tap the button.', []];
        }
        $this->store($chatId, $u->id, 'in', 'support', "🚩 Misread post".($r['channel'] ? " from {$r['channel']}" : '').":\n\n{$r['text']}\n\nPipStream read: "
            .(($r['result']['status'] ?? '') === 'parsed' ? BotScreens::signalLine($r['result']['signal']) : 'no signal ('.($r['result']['reason'] ?? '?').')'));

        return ['🙏 Thanks! The team will look at this post and reply here.', []];
    }

    // ================================================================ support
    private function support(int $chatId, ?User $user, string $text, ?string $name): void
    {
        $this->store($chatId, $user?->id, 'in', 'support', $text);
        if (! $user) {
            $this->api->send($chatId, '📨 Thanks, the PipStream team will reply here.');
            $this->welcome($chatId, $name);

            return;
        }
        // acknowledge once in a while, not after every line
        if (Cache::add("botack:{$chatId}", 1, now()->addMinutes(10))) {
            $this->api->send($chatId, "📨 Got it! Our team will reply here soon.\n\nTip: the buttons below the chat show your trades and status any time.");
        }
    }

    public static function store(int $chatId, ?string $userId, string $dir, string $kind, string $text, ?string $adminId = null, ?int $broadcastId = null): void
    {
        DB::table('bot_messages')->insert(['chat_id' => $chatId, 'user_id' => $userId, 'direction' => $dir, 'kind' => $kind,
            'text' => mb_substr($text, 0, 4000), 'admin_id' => $adminId, 'broadcast_id' => $broadcastId, 'created_at' => now()]);
    }
}
