<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\CopyRule;
use App\Pipstream\Accounts;
use App\Pipstream\Bus;
use App\Pipstream\ChannelTest;
use App\Pipstream\LiveGate;
use App\Pipstream\Presets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChannelController extends Controller
{
    public function index(Request $r)
    {
        $u = $r->user();
        $rules = $u->copyRules()->get()->keyBy('channel_id');
        $channels = Channel::join('subscriptions', 'subscriptions.channel_id', '=', 'channels.id')
            ->where('subscriptions.user_id', $u->id)->select('channels.*')->orderBy('channels.title')->get();
        $since = now()->subDays(7);
        $stats = DB::table('signals')->whereIn('channel_id', $channels->pluck('id'))->where('created_at', '>=', $since)
            ->selectRaw("channel_id, count(*) filter (where status='parsed') as parsed, max(created_at) as last_at")
            ->groupBy('channel_id')->get()->keyBy('channel_id');

        return ['channels' => $channels->map(fn ($c) => [
            'id' => $c->id, 'title' => $c->title, 'username' => $c->username,
            'signals_7d' => (int) ($stats[$c->id]->parsed ?? 0), 'last_signal_at' => $stats[$c->id]->last_at ?? null,
            'rule' => isset($rules[$c->id]) ? $this->rule_out($rules[$c->id]) : null,
        ])];
    }

    private function rule_out(CopyRule $rule): array
    {
        return ['enabled' => $rule->enabled, 'mode' => $rule->mode, 'style' => Presets::styleOf($rule->toArray()),
            'broker_account_id' => $rule->broker_account_id, 'max_lot' => $rule->max_lot, 'fixed_lots' => $rule->fixed_lots,
            'risk_pct' => $rule->risk_pct, 'stake' => $rule->stake, 'limits' => $rule->limits];
    }

    public function follow(Request $r, Accounts $accounts)
    {
        $data = $r->validate(['channels' => ['required', 'array', 'min:1', 'max:30'],
            'channels.*.tg_chat_id' => ['required', 'integer'], 'channels.*.title' => ['nullable', 'string', 'max:200'],
            'channels.*.username' => ['nullable', 'string', 'max:100']]);
        $acc = $r->user()->telegramAccounts()->where('status', 'active')->whereNotNull('session_ciphertext')->firstOrFail();
        $out = [];
        foreach ($data['channels'] as $c) {
            $out[] = $accounts->follow($r->user(), $acc, (int) $c['tg_chat_id'], $c['title'] ?? null, $c['username'] ?? null)->id;
        }

        return ['followed' => $out];
    }

    public function unfollow(Request $r, Channel $channel, Accounts $accounts)
    {
        $accounts->unfollow($r->user(), $channel);

        return ['ok' => true];
    }

    public function styles()
    {
        return ['styles' => collect(Presets::STYLES)->map(fn ($p, $k) => ['id' => $k, 'label' => $p['label'], 'blurb' => $p['blurb']])->values()];
    }

    public function rule(Request $r, Channel $channel, Bus $bus)
    {
        $u = $r->user();
        abort_unless(DB::table('subscriptions')->where(['user_id' => $u->id, 'channel_id' => $channel->id])->exists(), 404);
        $data = $r->validate([
            'style' => ['required', Rule::in(array_keys(Presets::STYLES))],
            'enabled' => ['boolean'],
            'broker_account_id' => ['nullable', 'string'],
            'live' => ['boolean'],
            'confirm_live' => ['boolean'],
            'fixed_lots' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'max_lot' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'max_daily_loss' => ['nullable', 'numeric', 'min:0'],
            'stake' => ['nullable', 'numeric', 'min:0.35', 'max:1000'],
            'confirm_first' => ['sometimes', 'boolean'],
        ]);
        $broker = $data['broker_account_id'] ?? null
            ? $u->brokerAccounts()->findOrFail($data['broker_account_id'])
            : $u->brokerAccounts()->where('connector', 'paper')->firstOrFail();
        $rule = CopyRule::firstOrNew(['user_id' => $u->id, 'channel_id' => $channel->id]);
        // Changing only the style (no "live" sent) keeps a rule that already copies to this broker live.
        $wantLive = array_key_exists('live', $data)
            ? (bool) $data['live']
            : $rule->exists && $rule->mode === 'live' && $rule->broker_account_id === $broker->id;
        $live = $wantLive && $broker->connector !== 'paper';
        $already = $rule->exists && $rule->mode === 'live' && $rule->broker_account_id === $broker->id;
        if ($live && ! $this->isDemo($broker)) {
            $billing = $u->billing()->with('plan')->first();   // fresh: never trust a cached plan for live money
            abort_unless($billing?->plan?->allow_live && $billing->isEntitled(), 402, 'Copying into a real-money account needs the Pro plan.');
        }
        if ($live) {
            $this->confirmLive($r, $broker, $already, (bool) ($data['confirm_live'] ?? false));
        }
        $preset = Presets::apply($data['style'], $data);
        // "Ask me before each trade" (answered in the PipStream bot) survives a style change
        $ask = array_key_exists('confirm_first', $data) ? (bool) $data['confirm_first'] : (bool) (($rule->limits ?? [])['confirm_first'] ?? false);
        abort_if($ask && ! \App\Pipstream\Bot\Bot::chatOf($u), 422, 'Connect Telegram alerts first (Settings): that is where PipStream asks you.');
        if ($ask) {
            $preset['limits']['confirm_first'] = true;
        }
        $rule->fill($preset + [
            'broker_account_id' => $broker->id, 'enabled' => $data['enabled'] ?? true,
            'mode' => $broker->connector === 'paper' ? 'paper' : ($live ? 'live' : 'shadow'),
            'tp_split' => 'equal', 'filters' => $rule->filters ?? []])->save();
        $bus->publish('config.changed', ['entity' => 'copy_rule', 'entity_id' => $rule->id]);

        return ['rule' => $this->rule_out($rule->fresh())];
    }

    private function followed(Request $r, Channel $channel): void
    {
        abort_unless(DB::table('subscriptions')->where(['user_id' => $r->user()->id, 'channel_id' => $channel->id])->exists(), 404);
    }

    /** Start a self-test: a one-time code for the customer to post in this channel. */
    public function startTest(Request $r, Channel $channel, ChannelTest $tests)
    {
        $this->followed($r, $channel);
        $place = (bool) ($r->validate(['place' => ['sometimes', 'boolean']])['place'] ?? false);
        abort_unless(\Illuminate\Support\Facades\RateLimiter::attempt("channel-test:{$r->user()->id}", 10, fn () => true, 600), 429,
            'That is a lot of tests. Try again in a few minutes.');
        $t = $tests->start($r->user(), $channel, $place);
        \App\Models\AuditLog::record($r->user()->id, $place ? 'channel_test_place' : 'channel_test', 'channel', $channel->id);

        return ['test' => $tests->present($t, $channel), 'sample' => $tests->sample()];
    }

    public function test(Request $r, Channel $channel, string $test, ChannelTest $tests)
    {
        $this->followed($r, $channel);
        $t = $tests->get($r->user(), $test);
        abort_unless($t && $t['channel_id'] === $channel->id, 404);

        return ['test' => $tests->present($t, $channel)];
    }

    /** Everything that has to be right for this channel to copy, checked now, plus its latest posts. */
    public function check(Request $r, Channel $channel)
    {
        $this->followed($r, $channel);
        $u = $r->user();
        $p = config('pipstream.stream_prefix', 'ps');
        $redis = rescue(fn () => app(Bus::class)->redis()->client(), null, false);
        $beat = $redis ? rescue(fn () => $redis->get("{$p}:health:intake"), null, false) : null;
        $engine = $beat && microtime(true) - (float) $beat < 30;
        $mine = $u->telegramAccounts()->orderByDesc('created_at')->first();
        $reader = DB::table('channel_readers')->join('telegram_accounts', 'telegram_accounts.id', '=', 'channel_readers.telegram_account_id')
            ->where('channel_readers.channel_id', $channel->id)->where('telegram_accounts.status', 'active')
            ->whereNotNull('telegram_accounts.session_ciphertext')->exists();
        $rule = $u->copyRules()->where('channel_id', $channel->id)->first();
        $broker = $rule ? $u->brokerAccounts()->find($rule->broker_account_id) : null;
        $billing = $u->billing()->with('plan')->first();
        $last = DB::table('signals')->where('channel_id', $channel->id)->max('created_at');
        $paused = (bool) $u->kill_switch;

        $checks = [
            ['id' => 'telegram', 'ok' => (bool) $mine?->isLinked(), 'label' => 'Your Telegram is connected',
                'detail' => $mine?->isLinked() ? null : 'Connect Telegram again in Settings.'],
            ['id' => 'reader', 'ok' => $reader, 'label' => 'PipStream is reading this channel',
                'detail' => $reader ? null : 'No connected Telegram account is reading it. Reconnect Telegram, then follow the channel again.'],
            ['id' => 'engine', 'ok' => $engine, 'label' => 'The copier is running',
                'detail' => $engine ? null : 'Our signal reader is not answering right now. We have been alerted; try again shortly.'],
            ['id' => 'copy', 'ok' => $rule && $rule->enabled && ! $paused, 'label' => 'Copying is on for this channel',
                'detail' => ! $rule || ! $rule->enabled ? 'Turn on "Copy this channel" below.' : ($paused ? 'You paused all copying on Home.' : null)],
            ['id' => 'broker', 'ok' => (bool) $broker?->enabled, 'label' => $broker ? 'Trades go to '.$broker->label.match ($rule->mode) { 'live' => ' (live)', 'shadow' => ' (watch only)', default => '' } : 'A broker account is chosen',
                'detail' => $broker?->enabled ? ($rule->mode === 'shadow' ? 'Watch only: signals are recorded, no orders are placed.' : null) : 'Pick where trades go below.'],
            ['id' => 'plan', 'ok' => (bool) $billing?->isEntitled(), 'label' => 'Your plan is active',
                'detail' => $billing?->isEntitled() ? null : 'Renew under Plan and billing to keep copying.'],
        ];
        $recent = DB::table('signals')->where('channel_id', $channel->id)->orderByDesc('created_at')->limit(8)
            ->get(['id', 'status', 'kind', 'reason', 'payload', 'created_at'])->map(function ($s) {
                $pl = json_decode($s->payload, true) ?: [];

                return ['id' => $s->id, 'status' => $s->status, 'at' => $s->created_at,
                    'posted_at' => $pl['source']['posted_at'] ?? null, 'text' => mb_strimwidth($pl['raw_text'] ?? '', 0, 140, '…'),
                    'read_as' => $s->status === 'parsed' ? ChannelTest::summary($pl) : 'Not a signal: '.ChannelTest::reason((string) $s->reason)];
            });

        return ['checks' => $checks, 'ok' => collect($checks)->every('ok'), 'last_post_at' => $last, 'recent' => $recent,
            'can_place' => (bool) $broker?->enabled && $rule?->mode !== 'shadow',
            'place_live' => $rule?->mode === 'live'];
    }

    /** Demo broker accounts (as the terminal itself reports) can be copied into on any plan. */
    private function isDemo(\App\Models\BrokerAccount $b): bool
    {
        return LiveGate::kind(LiveGate::state($b)) === 'demo';
    }

    /**
     * Real money needs an explicit yes. Without confirm_live the answer is 409 with the account
     * details, so the app can ask "Copy into real account 123 at Exness?" and send it again.
     * MetaTrader (EA): confirmed per login, so a terminal that switches to another real account
     * stops opening trades until confirmed again. Other brokers: confirmed when a channel goes live.
     */
    private function confirmLive(Request $r, \App\Models\BrokerAccount $broker, bool $already, bool $confirmed): void
    {
        $ask = fn (string $msg, array $account) => abort(response()->json(
            ['message' => $msg, 'needs_live_confirm' => true, 'account' => $account], 409));
        if ($broker->connector !== 'ea_bridge') {
            if (! $already && ! $confirmed) {
                $ask("Copy into {$broker->label} with real money? Every signal becomes a real order.", LiveGate::describe($broker, null));
            }

            return;
        }
        $state = LiveGate::state($broker);
        $kind = LiveGate::kind($state);
        if ($kind === 'demo' || LiveGate::confirmedFor($broker, $state)) {
            return;
        }
        if ($kind === 'unknown') {
            // keep an existing live rule (say a style change while MetaTrader is closed): the EA
            // still refuses real-money trades until this login is confirmed
            abort_unless($already, 422, 'PipStream can\'t see this MetaTrader right now, so it can\'t tell a demo from a real-money account. '
                .'Open MetaTrader with PipStream on a chart, wait for "Connected", then turn on live.');

            return;
        }
        if (! $confirmed) {
            $who = trim(($state['company'] ?? '').' '.($state['login'] ?? ''));
            $ask("This is a real-money account ({$who}). Copy signals into it with real money?", LiveGate::describe($broker, $state));
        }
        LiveGate::confirm($broker, $state, $r->user()->id);
    }
}
