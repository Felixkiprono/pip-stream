<?php

namespace App\Pipstream\Bot;

use App\Http\Controllers\BrokerController;
use App\Models\BrokerAccount;
use App\Models\Channel;
use App\Models\CopyRule;
use App\Models\User;
use App\Pipstream\Accounts;
use App\Pipstream\KillSwitch;
use App\Pipstream\Presets;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What the bot shows. Each screen returns [text, buttons]; buttons are rows of
 * label => callback data (or an https link). Words are plain and short on purpose: most
 * customers are not technical, so every screen says what is going on and what to tap next,
 * and anything bigger (brokers, real money, charts) links to the app.
 */
class BotScreens
{
    public const MENU = [
        'status' => '📊 Status', 'trades' => '📈 My trades', 'pnl' => '💰 Profit', 'channels' => '📡 Channels',
        'pause' => '⏸ Pause copying', 'resume' => '▶️ Resume copying', 'plan' => '💳 My plan', 'settings' => '⚙️ Settings',
        'help' => '💬 Help', 'app' => '🌐 Open app',
    ];

    public function __construct(private KillSwitch $kill, private Accounts $accounts) {}

    /** The big buttons at the bottom of the chat (Telegram's reply keyboard). */
    public function keyboard(User $u): array
    {
        $m = self::MENU;

        return BotApi::markup([
            [$m['status'], $m['trades']],
            [$m['pnl'], $m['channels']],
            [$this->paused($u) ? $m['resume'] : $m['pause'], $m['plan']],
            [$m['help'], $m['settings']],
        ], true);
    }

    public function paused(User $u): bool
    {
        return (bool) rescue(fn () => $this->kill->isOn($u->id), (bool) $u->kill_switch, false);
    }

    // ---------------------------------------------------------------- not linked yet
    public function welcome(?string $name = null): array
    {
        return ['👋 Hi'.($name ? " {$name}" : '')."! I'm the PipStream bot.\n\n"
            ."I send you a message when PipStream copies a trade for you, and you can check and control your copying right here.\n\n"
            .'First, connect your PipStream account. It takes a minute.',
            [['🔗 Connect my account' => 'link'], ['🆕 Create a free account' => Bot::app('/signup')], ['❓ What is PipStream?' => 'about']]];
    }

    public function about(): array
    {
        return ["PipStream copies the trading signals from Telegram channels you follow into your trading account, automatically.\n\n"
            ."1. You connect your Telegram (so PipStream can read your channels).\n"
            ."2. You pick the channels to copy.\n"
            ."3. Every new signal is copied for you, first into a free practice account.\n\n"
            .'Setting it up is easiest in the app. After that, this bot keeps you posted.',
            [['🆕 Create a free account' => Bot::app('/signup')], ['🔗 I already have an account' => 'link']]];
    }

    // ---------------------------------------------------------------- linked
    public function menu(User $u): array
    {
        $first = trim(explode(' ', (string) $u->name)[0] ?? '');

        return ['Hi'.($first !== '' ? " {$first}" : '')."! Tap a button below.\n\n"
            ."📊 Status: is copying on, is everything connected\n"
            ."📈 My trades: what is open right now\n"
            ."💰 Profit: today, this week, this month\n"
            ."📡 Channels: pause, change or follow channels\n"
            ."⏸ Pause: stop new trades any time\n\n"
            .'Anything else? Just type your question and our team will answer here.', []];
    }

    public function status(User $u): array
    {
        $paused = $this->paused($u);
        $global = (bool) rescue(fn () => $this->kill->isOn(null), false, false);
        $lines = ['📊 Your PipStream', ''];
        $lines[] = 'Copying: '.($global ? '⛔ Stopped for everyone right now (we are on it)' : ($paused ? '⏸ Paused by you' : '✅ On'));

        $tg = $u->telegramAccounts()->orderByDesc('created_at')->first();
        $lines[] = 'Your Telegram: '.($tg && $tg->isLinked() ? '✅ Connected' : '⚠️ Not connected (PipStream can\'t read your channels)');
        $channels = DB::table('subscriptions')->where('user_id', $u->id)->count();
        $lines[] = 'Channels: '.($channels ? $channels.' followed' : 'none yet');

        foreach ($u->brokerAccounts()->where('enabled', true)->orderBy('created_at')->get() as $b) {
            $lines[] = 'Account: '.$this->brokerLine($b);
        }
        $open = DB::table('positions')->where('user_id', $u->id)->where('status', 'open')->count();
        $today = (float) DB::table('positions')->where('user_id', $u->id)->where('status', 'closed')->where('closed_at', '>=', now()->startOfDay())->sum('profit');
        $lines[] = "Open trades: {$open} · Today: ".self::money($today);
        $lines[] = 'Plan: '.$this->planLine($u);

        $todo = [];
        if (! $tg || ! $tg->isLinked()) {
            $todo['🔗 Connect Telegram in the app'] = Bot::app('/start/telegram');
        } elseif (! $channels) {
            $todo['➕ Follow a channel'] = 'm:follow';
        }

        return [implode("\n", $lines), array_values(array_filter([
            $todo ?: null,
            [$paused ? '▶️ Resume copying' : '⏸ Pause copying' => $paused ? 'p:0' : 'p:1'],
            $u->brokerAccounts()->where('enabled', true)->where('connector', 'ea_bridge')->exists() ? ['📶 Ping MetaTrader' => 'm:ping'] : null,
            ['📈 My trades' => 'm:trades', '💰 Profit' => 'm:pnl'],
            ['🌐 Open PipStream' => Bot::app()],
        ]))];
    }

    /** /ping or "📶 Ping MetaTrader": ask each MetaTrader terminal to answer right now. */
    public function ping(User $u): array
    {
        $eas = $u->brokerAccounts()->where('enabled', true)->where('connector', 'ea_bridge')->orderBy('created_at')->limit(3)->get();
        if ($eas->isEmpty()) {
            return ["You don't have a MetaTrader account connected yet. Add one in the app under Brokers.", [['🌐 Open PipStream' => Bot::app('/app/brokers')]]];
        }
        $lines = array_column(app(\App\Pipstream\EaPing::class)->run($eas), 'text');
        $down = array_filter($lines, fn ($l) => str_contains($l, '🔌'));

        return ["📶 Ping MetaTrader\n\n".implode("\n\n", $lines), array_values(array_filter([
            ['🔁 Ping again' => 'm:ping'],
            $down ? ['🔧 Fix it in the app' => Bot::app('/app/brokers')] : null,
            ['📊 Status' => 'm:status'],
        ]))];
    }

    private function brokerLine(BrokerAccount $b): string
    {
        $label = $b->label ?: ucfirst($b->connector);
        if ($b->connector === 'paper') {
            return "{$label} (practice) ✅";
        }
        try {
            $p = app(BrokerController::class)->present($b);
        } catch (Throwable) {
            return "{$label} ❔";
        }
        $money = ($p['money']['kind'] ?? null) === 'real' ? ' · real money' : (($p['money']['kind'] ?? null) === 'demo' ? ' · demo' : '');
        if ($p['connected']) {
            return "{$label}{$money} ✅ online";
        }
        $state = $p['status']['state'] ?? null;

        return "{$label}{$money} ".($state === 'connecting' ? '⏳ waiting to connect' : '🔌 offline');
    }

    public function planLine(User $u): string
    {
        $p = $this->accounts->plan($u);
        if ($p['founding']) {
            return "🏅 Founding member #{$p['founding']}, free for life";
        }
        if ($p['lifetime']) {
            return '🏅 Free for life';
        }
        $left = $p['days_left'];

        return $p['name'].($left !== null ? ($left > 0 ? " · {$left} day".($left === 1 ? '' : 's').' left' : ' · ended') : '');
    }

    public function trades(User $u): array
    {
        $open = DB::table('positions')->where('user_id', $u->id)->where('status', 'open')->orderByDesc('opened_at')->limit(10)->get();
        $count = DB::table('positions')->where('user_id', $u->id)->where('status', 'open')->count();
        if (! $count) {
            return ["📈 No open trades right now.\n\nWhen a channel you follow posts a signal, PipStream copies it and tells you here.",
                [['📜 Recent closed trades' => 'm:closed'], ['📡 My channels' => 'm:channels']]];
        }
        $titles = DB::table('channels')->whereIn('id', $open->pluck('channel_id'))->pluck('title', 'id');
        $acct = DB::table('broker_accounts')->where('user_id', $u->id)->get(['id', 'label', 'connector'])->keyBy('id');
        $lines = ["📈 Open trades ({$count})", ''];
        foreach ($open as $i => $p) {
            $a = $acct[$p->broker_account_id] ?? null;
            $lines[] = ($i + 1).'. '.strtoupper((string) $p->side).' '.self::n($p->volume).' '.$p->symbol.' @ '.self::n($p->open_price)
                .($p->sl ? ' · SL '.self::n($p->sl) : '').($p->tp ? ' · TP '.self::n($p->tp) : '')
                .($p->profit !== null ? ' · '.self::money((float) $p->profit) : '');
            $lines[] = '    '.($titles[$p->channel_id] ?? 'channel').' · '.(($a->connector ?? '') === 'paper' ? 'practice' : ($a->label ?? 'broker'));
        }
        if ($count > 10) {
            $lines[] = '…and '.($count - 10).' more in the app.';
        }

        return [implode("\n", $lines), [
            ['🛑 Close all trades' => 'ca:ask'],
            ['🔄 Refresh' => 'm:trades', '📜 Recent closed' => 'm:closed'],
            ['🌐 See all in the app' => Bot::app('/app/trades')],
        ]];
    }

    public function closed(User $u): array
    {
        $rows = DB::table('positions')->where('user_id', $u->id)->where('status', 'closed')->orderByDesc('closed_at')->limit(8)->get();
        if ($rows->isEmpty()) {
            return ['📜 No closed trades yet.', [['📈 Open trades' => 'm:trades']]];
        }
        $lines = ['📜 Last closed trades', ''];
        foreach ($rows as $p) {
            $lines[] = ((float) $p->profit >= 0 ? '🟢 ' : '🔴 ').strtoupper((string) $p->side).' '.$p->symbol.' · '.self::money((float) $p->profit)
                .' · '.\Illuminate\Support\Carbon::parse($p->closed_at)->diffForHumans();
        }

        return [implode("\n", $lines), [['📈 Open trades' => 'm:trades', '💰 Profit' => 'm:pnl']]];
    }

    public function pnl(User $u): array
    {
        $row = fn ($from) => DB::table('positions')->where('user_id', $u->id)->where('status', 'closed')->where('closed_at', '>=', $from)
            ->selectRaw('count(*) as n, coalesce(sum(profit), 0) as pnl, count(*) filter (where profit > 0) as wins, count(*) filter (where profit < 0) as losses')->first();
        $t = $row(now()->startOfDay());
        $w = $row(now()->subDays(7));
        $m = $row(now()->subDays(30));
        $rate = fn ($r) => ($r->wins + $r->losses) ? ' · '.round(100 * $r->wins / ($r->wins + $r->losses)).'% wins' : '';
        $open = (float) DB::table('positions')->where('user_id', $u->id)->where('status', 'open')->sum('profit');

        return [implode("\n", ['💰 Your results (closed trades)', '',
            'Today: '.self::money((float) $t->pnl)." ({$t->n} trade".($t->n == 1 ? '' : 's').')',
            'Last 7 days: '.self::money((float) $w->pnl)." ({$w->n})".$rate($w),
            'Last 30 days: '.self::money((float) $m->pnl)." ({$m->n})".$rate($m),
            '', 'Open trades right now: '.self::money($open),
            '', 'Amounts are in your account currency. Practice trades are included.']),
            [['📈 My trades' => 'm:trades', '📜 Recent closed' => 'm:closed'], ['📊 Charts in the app' => Bot::app('/app/insights')]]];
    }

    public function channels(User $u): array
    {
        $rules = CopyRule::where('user_id', $u->id)->get()->keyBy('channel_id');
        $list = Channel::join('subscriptions', 'subscriptions.channel_id', '=', 'channels.id')
            ->where('subscriptions.user_id', $u->id)->orderBy('channels.title')->get(['channels.*']);
        if ($list->isEmpty()) {
            return ["📡 You don't follow any channels yet.\n\nTap Follow a channel and pick one from your Telegram. Its signals are copied for you, first into your free practice account.",
                [['➕ Follow a channel' => 'm:follow'], ['🌐 Open the app' => Bot::app('/app/channels')]]];
        }
        $week = DB::table('signals')->whereIn('channel_id', $list->pluck('id'))->where('status', 'parsed')->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('channel_id, count(*) n')->groupBy('channel_id')->pluck('n', 'channel_id');
        $lines = ['📡 Your channels', ''];
        $buttons = [];
        foreach ($list as $i => $c) {
            $r = $rules[$c->id] ?? null;
            $on = $r && $r->enabled;
            $lines[] = ($i + 1).'. '.self::title($c).' · '.($on ? '✅ copying' : '⏸ paused').($r ? ' · '.self::styleName($r) : '')
                .' · '.((int) ($week[$c->id] ?? 0)).' signals this week';
            $buttons[] = ['⚙️ '.self::short(self::title($c)) => 'ch:'.$c->id];
        }
        $lines[] = '';
        $lines[] = 'Tap a channel to pause it, change how much it trades, or stop following it.';
        $buttons[] = ['➕ Follow a channel' => 'm:follow'];
        $buttons[] = ['🌐 Manage in the app' => Bot::app('/app/channels')];

        return [implode("\n", $lines), $buttons];
    }

    public function channel(User $u, Channel $c): array
    {
        $r = CopyRule::where('user_id', $u->id)->where('channel_id', $c->id)->first();
        if (! $r) {
            return [self::title($c).' has no copy settings yet. Set it up in the app.', [['🌐 Open the app' => Bot::app('/app/channels')]]];
        }
        $b = BrokerAccount::find($r->broker_account_id);
        $style = Presets::styleOf($r->toArray());
        $ask = (bool) (($r->limits ?? [])['confirm_first'] ?? false);
        $mode = ['paper' => 'practice', 'live' => 'real trades', 'shadow' => 'watch only, no trades'][$r->mode] ?? $r->mode;
        $text = implode("\n", [
            '📡 '.self::title($c), '',
            'Copying: '.($r->enabled ? '✅ On' : '⏸ Paused'),
            'Style: '.self::styleName($r).(isset(Presets::STYLES[$style]) ? ' ('.Presets::STYLES[$style]['blurb'].')' : ''),
            'Goes to: '.($b?->label ?: 'your account')." ({$mode})",
            'Ask me before each trade: '.($ask ? '✅ Yes' : 'No'),
            '', 'Tap a style to change how much each signal trades.',
        ]);
        $styles = [];
        foreach (['careful', 'balanced', 'bold'] as $s) {
            $styles[($style === $s ? '● ' : '').Presets::STYLES[$s]['label']] = "cs:{$c->id}:{$s}";
        }

        return [$text, [
            [$r->enabled ? '⏸ Pause this channel' : '▶️ Copy this channel' => "ce:{$c->id}:".($r->enabled ? 0 : 1)],
            $styles,
            [($ask ? '🙋 Ask me first: on' : '🙋 Ask me first: off') => "cq:{$c->id}:".($ask ? 0 : 1)],
            ['🗑 Stop following' => "cu:{$c->id}", '⬅️ Back' => 'm:channels'],
        ]];
    }

    public function plan(User $u): array
    {
        $p = $this->accounts->plan($u);
        $lines = ['💳 Your plan', '', $this->planLine($u)];
        if ($p['until'] && ! $p['lifetime']) {
            $lines[] = ($p['days_left'] > 0 ? 'Ends on ' : 'Ended on ').$p['until']->format('j M Y');
        }
        $lines[] = '';
        $lines[] = $p['limits']['live'] ? '✅ Real-money copying included' : 'Practice copying included. Pro adds copying into real-money accounts.';
        $lines[] = "Broker accounts: {$p['usage']['brokers']} of {$p['limits']['brokers']} · Channels: {$p['usage']['channels']}";
        $buttons = $p['lifetime'] ? [['🌐 Open the app' => Bot::app()]] : [[($p['tier'] === 'premium' ? '🔁 Renew in the app' : '⭐ Upgrade in the app') => Bot::app('/app/billing')]];

        return [implode("\n", $lines), $buttons];
    }

    public function settings(User $u, object $chat): array
    {
        return [implode("\n", ['⚙️ Bot settings', '',
            'Trade alerts: '.($chat->trade_alerts ? '✅ On' : '🔕 Off'),
            'Daily summary (evening): '.($chat->daily_summary ? '✅ On' : '🔕 Off'),
            '', "Connected to {$u->email}"]), [
                [($chat->trade_alerts ? '🔕 Turn off trade alerts' : '🔔 Turn on trade alerts') => 'st:trade:'.($chat->trade_alerts ? 0 : 1)],
                [($chat->daily_summary ? '🔕 Turn off daily summary' : '🔔 Turn on daily summary') => 'st:daily:'.($chat->daily_summary ? 0 : 1)],
                ['🔌 Disconnect this chat' => 'st:unlink'],
                ['🌐 More settings in the app' => Bot::app('/app/settings')],
            ]];
    }

    public function help(): array
    {
        return [implode("\n", ['💬 Need help?', '',
            'Just type your question here. Our team reads every message and replies in this chat.', '',
            'You can also:',
            '• Forward me a post from a signal channel to see how PipStream reads it',
            '• Use the buttons below the chat to check trades, pause copying or manage channels',
            '• Type /ping to check your MetaTrader is answering right now', '',
            'Adding a broker, real-money accounts and charts are in the app.']),
            [['🧪 Test a signal post' => 'm:test'], ['🌐 Open PipStream' => Bot::app()]]];
    }

    public function test(): array
    {
        return ["🧪 Test a signal\n\nIn Telegram, open a signal channel, press and hold a post, tap Forward and pick this chat (PipStream bot).\n\nI'll show you how PipStream reads it: buy or sell, the price, stop loss and take profit.", []];
    }

    // ---------------------------------------------------------------- helpers
    public static function title(object $c): string
    {
        return (string) ($c->title ?: ($c->username ? '@'.$c->username : 'Channel'));
    }

    public static function short(string $s, int $n = 28): string
    {
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1).'…' : $s;
    }

    public static function styleName(CopyRule $r): string
    {
        $s = Presets::styleOf($r->toArray());

        return Presets::STYLES[$s]['label'] ?? 'Custom';
    }

    public static function money(float $v): string
    {
        return ($v > 0 ? '+' : ($v < 0 ? '−' : '')).number_format(abs($v), 2);
    }

    public static function n($v): string
    {
        return $v === null ? '' : rtrim(rtrim(number_format((float) $v, 5, '.', ''), '0'), '.');
    }

    /** One line for a parsed signal, e.g. "BUY XAUUSD · entry 2348–2350 · SL 2340 · TP 2360 / 2375". */
    public static function signalLine(array $s): string
    {
        $e = $s['entry'] ?? null;
        $entry = null;
        if ($e) {
            $entry = isset($e['from'], $e['to']) && $e['from'] !== null ? self::n($e['from']).'–'.self::n($e['to'])
                : (isset($e['price']) && $e['price'] !== null ? self::n($e['price']) : null);
            if ($entry && in_array($e['type'] ?? '', ['limit', 'stop'], true)) {
                $entry .= " ({$e['type']} order)";
            }
        }
        $kind = $s['kind'] ?? 'open';
        if ($kind === 'modify') {
            return ($s['move_sl_to_entry'] ?? false) ? 'Move stop loss to entry' : trim('Update '.(isset($s['new_sl']) ? 'SL '.self::n($s['new_sl']).' ' : '').(isset($s['new_tp']) ? 'TP '.self::n($s['new_tp']) : ''));
        }
        if ($kind === 'close') {
            return 'Close '.(($s['close_fraction'] ?? 1) < 1 ? round($s['close_fraction'] * 100).'% of ' : '').($s['symbol'] ?? 'the trade');
        }

        return trim(strtoupper((string) ($s['side'] ?? '')).' '.($s['symbol'] ?? ''))
            .($entry ? ' · entry '.$entry : ' · at market price')
            .(isset($s['sl']) && $s['sl'] !== null ? ' · SL '.self::n($s['sl']) : '')
            .(! empty($s['tps']) ? ' · TP '.implode(' / ', array_map([self::class, 'n'], $s['tps'])) : '');
    }
}
