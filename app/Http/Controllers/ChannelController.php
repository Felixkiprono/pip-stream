<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\CopyRule;
use App\Pipstream\Accounts;
use App\Pipstream\Bus;
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
            'fixed_lots' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'max_lot' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'max_daily_loss' => ['nullable', 'numeric', 'min:0'],
            'stake' => ['nullable', 'numeric', 'min:0.35', 'max:1000'],
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
        if ($live && ! $this->isDemo($broker)) {
            $billing = $u->billing()->with('plan')->first();   // fresh: never trust a cached plan for live money
            abort_unless($billing?->plan?->allow_live && $billing->isEntitled(), 402, 'Copying into a real-money account needs the Pro plan.');
        }
        $rule->fill(Presets::apply($data['style'], $data) + [
            'broker_account_id' => $broker->id, 'enabled' => $data['enabled'] ?? true,
            'mode' => $broker->connector === 'paper' ? 'paper' : ($live ? 'live' : 'shadow'),
            'tp_split' => 'equal', 'filters' => $rule->filters ?? []])->save();
        $bus->publish('config.changed', ['entity' => 'copy_rule', 'entity_id' => $rule->id]);

        return ['rule' => $this->rule_out($rule->fresh())];
    }

    /** Demo broker accounts (as the terminal itself reports) can be copied into on any plan. */
    private function isDemo(\App\Models\BrokerAccount $b): bool
    {
        if ($b->connector !== 'ea_bridge') {
            return false;
        }
        $p = config('pipstream.stream_prefix', 'ps');
        $raw = rescue(fn () => app(\App\Pipstream\Bus::class)->redis()->client()->get("{$p}:eares:{$b->id}:state"), null, false);
        $state = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($state) && ($state['demo'] ?? false) === true;
    }
}
