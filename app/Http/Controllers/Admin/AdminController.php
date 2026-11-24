<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\ParserProfile;
use App\Models\User;
use App\Pipstream\Bus;
use App\Pipstream\KillSwitch;
use App\Pipstream\MailSettings;
use App\Pipstream\TelegramLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Throwable;

class AdminController extends Controller
{
    public function overview(Bus $bus, KillSwitch $kill)
    {
        $r = $bus->redis()->client();
        $p = config('pipstream.stream_prefix');
        $workers = [];
        foreach (['intake', 'risk', 'executor'] as $w) {
            $beat = $r->get("{$p}:health:{$w}");
            $workers[] = ['name' => $w, 'last_beat' => $beat ? (float) $beat : null,
                'healthy' => $beat && microtime(true) - (float) $beat < 30];
        }
        $day = now()->subDay();

        return [
            'global_pause' => $kill->isOn(null),
            'workers' => $workers,
            'counts' => [
                'users' => User::count(),
                'channels' => Channel::where('active', true)->count(),
                'telegram_linked' => DB::table('telegram_accounts')->where('status', 'active')->whereNotNull('session_ciphertext')->count(),
                'signals_24h' => DB::table('signals')->where('created_at', '>=', $day)->where('status', 'parsed')->count(),
                'rejected_24h' => DB::table('signals')->where('created_at', '>=', $day)->where('status', 'rejected')->count(),
                'orders_24h' => DB::table('orders')->where('created_at', '>=', $day)->where('status', 'executed')->count(),
                'failed_24h' => DB::table('orders')->where('created_at', '>=', $day)->where('status', 'failed')->count(),
            ],
            'latency_ms_p50' => DB::table('orders')->where('created_at', '>=', $day)->where('action', 'open')
                ->selectRaw('percentile_cont(0.5) within group (order by latency_ms) as p')->value('p'),
        ];
    }

    /**
     * The Overview dashboard: trends with deltas vs the previous period, the signal → trade
     * pipeline, latency, top channels, plan mix and a live activity feed.
     */
    public function dashboard(Request $req, Bus $bus, KillSwitch $kill)
    {
        $days = (int) ($req->validate(['days' => ['nullable', 'integer', 'in:1,7,14,30']])['days'] ?? 7);
        $from = $days === 1 ? now()->subDay() : now()->startOfDay()->subDays($days - 1);
        $prev = $from->copy()->subSeconds(now()->diffInSeconds($from));
        $bucket = $days === 1 ? 'hour' : 'day';

        $series = collect(DB::select(<<<SQL
            with b as (select generate_series(date_trunc('{$bucket}', :f1::timestamptz), now(), interval '1 {$bucket}') as t)
            select b.t,
              (select count(*) from signals where status = 'parsed' and date_trunc('{$bucket}', created_at) = b.t) as signals,
              (select count(*) from signals where status = 'rejected' and date_trunc('{$bucket}', created_at) = b.t) as rejected,
              (select count(*) from orders where status = 'executed' and date_trunc('{$bucket}', created_at) = b.t) as executed,
              (select count(*) from orders where status <> 'executed' and date_trunc('{$bucket}', created_at) = b.t) as failed,
              (select count(*) from users where date_trunc('{$bucket}', created_at) = b.t) as signups,
              (select percentile_cont(0.5) within group (order by latency_ms) from orders
                 where status = 'executed' and action = 'open' and date_trunc('{$bucket}', created_at) = b.t) as p50
            from b order by b.t
            SQL, ['f1' => $from->toIso8601String()]))->map(fn ($x) => [
            't' => $x->t, 'signals' => (int) $x->signals, 'rejected' => (int) $x->rejected, 'executed' => (int) $x->executed,
            'failed' => (int) $x->failed, 'signups' => (int) $x->signups, 'p50' => $x->p50 !== null ? (int) round($x->p50) : null]);

        $window = function ($a, $b) {
            $q = fn ($t) => DB::table($t)->where('created_at', '>=', $a)->where('created_at', '<', $b);

            return [
                'signals' => $q('signals')->where('status', 'parsed')->count(),
                'rejected' => $q('signals')->where('status', 'rejected')->count(),
                'intents' => $q('trade_intents')->count(),
                'executed' => $q('orders')->where('status', 'executed')->count(),
                'failed' => $q('orders')->where('status', '<>', 'executed')->count(),
                'signups' => $q('users')->count(),
                'active' => DB::table('positions')->where('opened_at', '>=', $a)->where('opened_at', '<', $b)->distinct()->count('user_id'),
                'p50' => ($v = $q('orders')->where('status', 'executed')->where('action', 'open')
                    ->selectRaw('percentile_cont(0.5) within group (order by latency_ms) as p')->value('p')) !== null ? (int) round($v) : null,
            ];
        };
        $now = $window($from, now()->addSecond());
        $before = $window($prev, $from);

        $lat = DB::table('orders')->where('created_at', '>=', $from)->where('status', 'executed')->whereNotNull('latency_ms')
            ->selectRaw(<<<'SQL'
                count(*) filter (where latency_ms < 100) as a, count(*) filter (where latency_ms >= 100 and latency_ms < 250) as b,
                count(*) filter (where latency_ms >= 250 and latency_ms < 500) as c, count(*) filter (where latency_ms >= 500 and latency_ms < 1000) as d,
                count(*) filter (where latency_ms >= 1000) as e,
                percentile_cont(0.95) within group (order by latency_ms) as p95
                SQL)->first();

        $subs = DB::table('subscriptions')->selectRaw('channel_id, count(*) as n')->groupBy('channel_id')->pluck('n', 'channel_id');
        $chan = DB::table('channels')->leftJoin('signals', fn ($j) => $j->on('signals.channel_id', '=', 'channels.id')->where('signals.created_at', '>=', $from))
            ->where('channels.active', true)->groupBy('channels.id', 'channels.title')
            ->selectRaw("channels.id, channels.title, count(signals.id) filter (where signals.status='parsed') as parsed, count(signals.id) filter (where signals.status='rejected') as rejected, max(signals.created_at) as last")
            ->orderByDesc('parsed')->limit(8)->get()
            ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'parsed' => (int) $c->parsed, 'rejected' => (int) $c->rejected,
                'followers' => (int) ($subs[$c->id] ?? 0), 'last' => $c->last,
                'rate' => ($c->parsed + $c->rejected) ? (int) round(100 * $c->parsed / ($c->parsed + $c->rejected)) : null]);

        $plans = DB::table('users')->leftJoin('billing_subscriptions', 'billing_subscriptions.user_id', '=', 'users.id')
            ->selectRaw("coalesce(billing_subscriptions.plan_id, 'none') as plan, count(*) as n")->groupBy('plan')->pluck('n', 'plan');

        $titles = DB::table('channels')->pluck('title', 'id');
        $names = DB::table('users')->pluck('email', 'id');
        $feed = collect()
            ->merge(DB::table('signals')->orderByDesc('created_at')->limit(12)->get(['status', 'kind', 'symbol', 'side', 'channel_id', 'reason', 'created_at'])
                ->map(fn ($s) => ['type' => $s->status === 'parsed' ? 'signal' : 'rejected', 'at' => $s->created_at,
                    'text' => $s->status !== 'parsed' ? 'Post ignored ('.str_replace('_', ' ', (string) $s->reason).')'
                        : match ($s->kind) {
                            'open' => 'Signal: '.strtoupper((string) $s->side).' '.$s->symbol,
                            'modify' => 'Follow-up: modify'.($s->symbol ? " {$s->symbol}" : ''),
                            'close' => 'Follow-up: close'.($s->symbol ? " {$s->symbol}" : ''),
                            default => 'Signal: '.trim("{$s->kind} {$s->symbol}"),
                        },
                    'sub' => $titles[$s->channel_id] ?? null]))
            ->merge(DB::table('orders')->orderByDesc('created_at')->limit(12)->get(['status', 'action', 'user_id', 'latency_ms', 'error', 'created_at'])
                ->map(fn ($o) => ['type' => $o->status === 'executed' ? 'order' : 'failed', 'at' => $o->created_at,
                    'text' => ucfirst($o->action).($o->status === 'executed' ? ' executed' : ' failed').($o->latency_ms ? " in {$o->latency_ms} ms" : ''),
                    'sub' => $names[$o->user_id] ?? null]))
            ->merge(DB::table('users')->orderByDesc('created_at')->limit(6)->get(['email', 'created_at'])
                ->map(fn ($u) => ['type' => 'signup', 'at' => $u->created_at, 'text' => 'New customer', 'sub' => $u->email]))
            ->sortByDesc('at')->take(14)->values();

        $p = config('pipstream.stream_prefix');
        $r = $bus->redis()->client();
        $workers = collect(['intake', 'risk', 'executor'])->map(function ($w) use ($r, $p) {
            $beat = $r->get("{$p}:health:{$w}");

            return ['name' => $w, 'healthy' => $beat && microtime(true) - (float) $beat < 30, 'last_beat' => $beat ? (float) $beat : null];
        });

        return [
            'days' => $days, 'bucket' => $bucket,
            'global_pause' => $kill->isOn(null),
            'workers' => $workers,
            'series' => $series,
            'now' => $now, 'before' => $before,
            'totals' => [
                'users' => DB::table('users')->count(),
                'telegram' => DB::table('telegram_accounts')->where('status', 'active')->whereNotNull('session_ciphertext')->count(),
                'channels' => DB::table('channels')->where('active', true)->count(),
                'open_positions' => DB::table('positions')->where('status', 'open')->count(),
            ],
            'latency' => ['buckets' => [['<100 ms', (int) $lat->a], ['100–250', (int) $lat->b], ['250–500', (int) $lat->c], ['0.5–1 s', (int) $lat->d], ['>1 s', (int) $lat->e]],
                'p95' => $lat->p95 !== null ? (int) round($lat->p95) : null],
            'channels' => $chan,
            'plans' => $plans,
            'feed' => $feed,
        ];
    }

    /**
     * The System page: is every part running and configured? Reports set/missing and counts
     * only, never a secret's value. Engine-only settings (Telegram API ID/hash, signing keys)
     * are reported by the engine workers themselves, since Laravel never holds them.
     */
    public function system(Request $req, Bus $bus)
    {
        $checks = [];
        $add = function (string $group, string $name, string $state, string $detail = '') use (&$checks) {
            $checks[] = compact('group', 'name', 'state', 'detail'); // state: ok | warn | fail
        };

        // ---------------------------------------------------------------- infrastructure
        try {
            DB::select('select 1');
            $add('Infrastructure', 'Database', 'ok', DB::connection()->getDatabaseName());
        } catch (Throwable $e) {
            $add('Infrastructure', 'Database', 'fail', 'not reachable');
        }
        $r = null;
        try {
            $r = $bus->redis()->client();
            $r->get(config('pipstream.stream_prefix').':health:intake');
            $add('Infrastructure', 'Redis', 'ok');
        } catch (Throwable $e) {
            $add('Infrastructure', 'Redis', 'fail', 'not reachable');
        }
        $beat = Cache::get('pipstream:consumer:beat');
        $add('Infrastructure', 'Event consumer (pipstream:consume)', $beat && microtime(true) - $beat < 30 ? 'ok' : 'fail',
            $beat ? 'last seen '.round(microtime(true) - $beat).'s ago' : 'not running: Telegram linking and alerts need it');
        try {
            $q = Queue::size();
            $add('Infrastructure', 'Queue', $q > 100 ? 'warn' : 'ok', "{$q} waiting (".config('queue.default').')');
        } catch (Throwable $e) {
            $add('Infrastructure', 'Queue', 'warn', 'size unknown');
        }
        try {
            Http::timeout(2)->get(rtrim(config('pipstream.engine_url'), '/').'/health')->throw();
            $add('Infrastructure', 'Engine HTTP (parse preview)', 'ok', config('pipstream.engine_url'));
        } catch (Throwable $e) {
            $add('Infrastructure', 'Engine HTTP (parse preview)', 'fail', 'not reachable at '.config('pipstream.engine_url'));
        }

        // ---------------------------------------------------------------- engine workers
        $p = config('pipstream.stream_prefix');
        $workers = [];
        foreach (['intake', 'risk', 'executor'] as $w) {
            $beatW = $r?->get("{$p}:health:{$w}");
            $status = json_decode((string) ($r?->get("{$p}:health:{$w}:status") ?? ''), true) ?: null;
            $healthy = $beatW && microtime(true) - (float) $beatW < 30;
            $workers[] = ['name' => $w, 'healthy' => (bool) $healthy, 'last_beat' => $beatW ? (float) $beatW : null, 'status' => $status];
            $add('Engine', ucfirst($w).' worker', $healthy ? 'ok' : 'fail', $healthy ? 'heartbeat '.round(microtime(true) - (float) $beatW).'s ago' : 'no heartbeat');
        }
        $in = $workers[0]['status'];
        $risk = $workers[1]['status'];
        $ex = $workers[2]['status'];
        if ($risk) {
            $add('Engine', 'Intent signing key (risk)', $risk['signing_key'] ? 'ok' : 'fail', $risk['signing_key'] ? 'set' : 'PS_INTENT_SIGNING_KEY missing');
        }
        if ($ex) {
            $add('Engine', 'Intent verify key (executor)', $ex['verify_key'] ? 'ok' : 'fail', $ex['verify_key'] ? 'set' : 'PS_INTENT_VERIFY_KEY missing');
            $add('Engine', 'Broker credential key (executor)', $ex['executor_key'] ? 'ok' : 'fail', $ex['executor_key'] ? 'set' : 'PS_EXECUTOR_PRIVATE_KEY missing');
            $add('Engine', 'Brokers', 'ok', implode(', ', array_filter(['paper', $ex['mt5_configured'] ? 'MT5' : null, $ex['deriv_configured'] ? 'Deriv' : null])));
        }

        // ---------------------------------------------------------------- Telegram
        if ($in) {
            $mode = $in['telegram_mode'];
            $add('Telegram', 'API ID / hash (my.telegram.org)', $in['telegram_api_configured'] ? 'ok' : ($mode === 'real' ? 'fail' : 'warn'),
                $in['telegram_api_configured'] ? 'set' : 'PS_TELEGRAM_API_ID / PS_TELEGRAM_API_HASH missing in the engine env');
            $add('Telegram', 'Mode', $mode === 'real' ? 'ok' : 'warn', $mode === 'stand-in' ? 'stand-in Telegram (code 12345): not reading real channels' : $mode);
            $add('Telegram', 'Session key (intake)', $in['intake_key'] ? 'ok' : 'fail', $in['intake_key'] ? 'set' : 'PS_INTAKE_PRIVATE_KEY missing');
            $add('Telegram', 'Connected reader sessions', $in['sessions_connected'] > 0 ? 'ok' : 'warn', (string) $in['sessions_connected']);
            $add('Telegram', 'Channels being read', $in['channels_tracked'] && $in['channels_with_reader'] < $in['channels_tracked'] ? 'warn' : 'ok',
                "{$in['channels_with_reader']} of {$in['channels_tracked']} have a connected reader");
            $add('Telegram', '"PipStream read a signal" notes', $in['confirm_to_saved'] ? 'warn' : 'ok',
                $in['confirm_to_saved'] ? 'on: every post read is noted in the reader\'s Saved Messages (testing only)' : 'off');
        } else {
            $add('Telegram', 'Intake worker', 'fail', 'not reporting: is the engine running?');
        }
        $bot = config('pipstream.bot_token');
        $add('Telegram', 'Alerts bot token', $bot ? 'ok' : 'warn', $bot ? '@'.config('pipstream.bot_username') : 'PS_TELEGRAM_BOT_TOKEN not set: no Telegram alerts');

        // ---------------------------------------------------------------- web app config
        $add('Web app', 'App key', config('app.key') ? 'ok' : 'fail', config('app.key') ? 'set' : 'APP_KEY missing');
        $add('Web app', 'Environment', config('app.env') === 'production' && config('app.debug') ? 'fail' : 'ok',
            config('app.env').(config('app.debug') ? ', debug on' : ''));
        $add('Web app', 'Telegram session public key', config('pipstream.intake_public_key') ? 'ok' : 'fail',
            config('pipstream.intake_public_key') ? 'set' : 'PS_INTAKE_PUBLIC_KEY missing');
        $add('Web app', 'Broker credential public key', config('pipstream.executor_public_key') ? 'ok' : 'fail',
            config('pipstream.executor_public_key') ? 'set' : 'PS_EXECUTOR_PUBLIC_KEY missing');
        $add('Web app', 'Payments (Paystack)', config('pipstream.paystack_secret') ? 'ok' : 'warn',
            config('pipstream.paystack_secret') ? 'set ('.config('pipstream.paystack_currency').')' : 'PAYSTACK_SECRET_KEY not set');
        $mail = MailSettings::present();
        $add('Web app', 'Email (Resend)', $mail['source'] === 'none' ? 'warn' : 'ok', $mail['source'] === 'none'
            ? 'no Resend API key: set it in Admin → Email (password resets and welcome emails are not sent)'
            : "key {$mail['key_hint']} from ".($mail['source'] === 'admin' ? 'Admin → Email' : '.env').", sending as {$mail['from_address']}");
        $slots = (int) config('pipstream.founding_slots');
        $taken = User::whereNotNull('founding_number')->count();
        $add('Web app', 'Founding members', 'ok', "{$taken} of {$slots} free-for-life spots taken");

        $acc = $req->user()->telegramAccounts()->orderByDesc('created_at')->first();

        return [
            'checks' => $checks,
            'workers' => $workers,
            'my_telegram' => $acc ? ['linked' => $acc->isLinked(), 'status' => $acc->status, 'phone_hint' => $acc->phone_hint,
                'last_error' => $acc->last_error] : null,
        ];
    }

    /** Send a test note to the admin's own Telegram Saved Messages through the engine. */
    public function telegramTest(Request $req, TelegramLink $link)
    {
        $acc = $req->user()->telegramAccounts()->orderByDesc('created_at')->first();
        abort_unless($acc && $acc->isLinked(), 409, 'Connect your own Telegram first (Customer app → Telegram).');
        AuditLog::record($req->user()->id, 'telegram_test', 'telegram_account', $acc->id, []);

        return $link->request($acc, 'test');
    }

    public function killSwitch(Request $req, KillSwitch $kill)
    {
        $on = $req->validate(['on' => ['required', 'boolean']])['on'];
        $kill->set($on, null, $req->user()->id);

        return ['global_pause' => $on];
    }

    public function channels()
    {
        $readers = DB::table('channel_readers')->join('telegram_accounts', 'telegram_accounts.id', '=', 'channel_readers.telegram_account_id')
            ->get(['channel_readers.channel_id', 'channel_readers.role', 'telegram_accounts.id', 'telegram_accounts.status', 'telegram_accounts.phone_hint'])
            ->groupBy('channel_id');
        $subs = DB::table('subscriptions')->selectRaw('channel_id, count(*) as n')->groupBy('channel_id')->pluck('n', 'channel_id');
        $sig = DB::table('signals')->where('created_at', '>=', now()->subDays(7))
            ->selectRaw("channel_id, count(*) filter (where status='parsed') as parsed, count(*) filter (where status='rejected') as rejected")
            ->groupBy('channel_id')->get()->keyBy('channel_id');

        return [
            'channels' => Channel::orderBy('title')->get()->map(fn ($c) => [
                'id' => $c->id, 'title' => $c->title, 'username' => $c->username, 'tg_chat_id' => $c->tg_chat_id,
                'active' => $c->active, 'parser_profile_id' => $c->parser_profile_id, 'followers' => (int) ($subs[$c->id] ?? 0),
                'parsed_7d' => (int) ($sig[$c->id]->parsed ?? 0), 'rejected_7d' => (int) ($sig[$c->id]->rejected ?? 0),
                'readers' => ($readers[$c->id] ?? collect())->map(fn ($r) => ['account' => $r->id, 'role' => $r->role,
                    'status' => $r->status, 'phone_hint' => $r->phone_hint])->values()]),
            'profiles' => ParserProfile::orderBy('name')->get(['id', 'name', 'version', 'min_confidence', 'config']),
        ];
    }

    public function updateChannel(Request $req, Channel $channel, Bus $bus)
    {
        $d = $req->validate(['active' => ['sometimes', 'boolean'], 'title' => ['sometimes', 'string', 'max:200'],
            'parser_profile_id' => ['sometimes', 'exists:parser_profiles,id']]);
        $channel->update($d);
        AuditLog::record($req->user()->id, 'channel_updated', 'channel', $channel->id, $d);
        $bus->publish('config.changed', ['entity' => 'channel', 'entity_id' => $channel->id]);

        return ['ok' => true];
    }

    /** Ask the engine how a post would parse, without storing anything. */
    public function parsePreview(Request $req)
    {
        $d = $req->validate(['text' => ['required', 'string', 'max:4000'], 'channel_id' => ['nullable', 'string'],
            'reply_to_id' => ['nullable', 'integer']]);
        try {
            return Http::timeout(5)->post(rtrim(config('pipstream.engine_url'), '/').'/parse-preview', $d)->throw()->json();
        } catch (Throwable $e) {
            abort(503, 'The engine is not reachable right now.');
        }
    }

    public function review()
    {
        $titles = DB::table('channels')->pluck('title', 'id');

        return ['rejected' => DB::table('signals')->where('status', 'rejected')->orderByDesc('created_at')->limit(100)
            ->get(['id', 'channel_id', 'message_id', 'reason', 'confidence', 'payload', 'created_at'])
            ->map(fn ($s) => ['id' => $s->id, 'channel_id' => $s->channel_id, 'channel' => $titles[$s->channel_id] ?? null,
                'message_id' => $s->message_id, 'reason' => $s->reason, 'confidence' => $s->confidence,
                'text' => json_decode($s->payload, true)['raw_text'] ?? '', 'at' => $s->created_at])];
    }

    public function users(Request $req)
    {
        $d = $req->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,suspended'],
            'role' => ['nullable', 'in:superadmin,admin,customer']]);
        $q = User::query()->with('billing')->orderByDesc('created_at');
        if ($s = trim($d['q'] ?? '')) {
            $q->where(fn ($w) => $w->where('email', 'ilike', "%{$s}%")->orWhere('name', 'ilike', "%{$s}%")->orWhere('id', $s));
        }
        foreach (['status', 'role'] as $f) {
            if (! empty($d[$f])) {
                $q->where($f, $d[$f]);
            }
        }
        $users = $q->limit(200)->get();
        $ids = $users->pluck('id');
        $chan = DB::table('subscriptions')->whereIn('user_id', $ids)->selectRaw('user_id, count(*) n')->groupBy('user_id')->pluck('n', 'user_id');
        $tg = DB::table('telegram_accounts')->whereIn('user_id', $ids)->where('status', 'active')->whereNotNull('session_ciphertext')->pluck('user_id')->flip();
        $open = DB::table('positions')->whereIn('user_id', $ids)->where('status', 'open')->selectRaw('user_id, count(*) n')->groupBy('user_id')->pluck('n', 'user_id');

        return ['users' => $users->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'status' => $u->status,
            'paused' => (bool) $u->kill_switch, 'plan' => $u->billing?->plan_id, 'founding' => $u->founding_number, 'billing' => $u->billing?->status,
            'channels' => (int) ($chan[$u->id] ?? 0), 'open_trades' => (int) ($open[$u->id] ?? 0),
            'telegram' => isset($tg[$u->id]), 'joined' => $u->created_at])];
    }

    /** One customer: everything support needs on one screen. Never returns credentials or sessions. */
    public function user(User $user)
    {
        $b = $user->billing;
        $titles = DB::table('channels')->pluck('title', 'id');

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'founding' => $user->founding_number,
                'status' => $user->status, 'paused' => (bool) $user->kill_switch, 'joined' => $user->created_at],
            'billing' => $b ? ['plan' => $b->plan_id, 'status' => $b->status, 'period_end' => $b->current_period_end, 'provider' => $b->provider] : null,
            'telegram' => $user->telegramAccounts()->orderByDesc('created_at')->get()->map(fn ($a) => [
                'id' => $a->id, 'status' => $a->status, 'linked' => $a->isLinked(), 'phone_hint' => $a->phone_hint,
                'last_error' => $a->last_error, 'since' => $a->created_at]),
            'channels' => DB::table('subscriptions')->join('channels', 'channels.id', '=', 'subscriptions.channel_id')
                ->leftJoin('copy_rules', fn ($j) => $j->on('copy_rules.channel_id', '=', 'channels.id')->on('copy_rules.user_id', '=', 'subscriptions.user_id'))
                ->where('subscriptions.user_id', $user->id)
                ->get(['channels.id', 'channels.title', 'channels.username', 'channels.active', 'copy_rules.mode', 'copy_rules.enabled', 'copy_rules.sizing_mode', 'subscriptions.created_at']),
            'brokers' => $user->brokerAccounts()->get()->map(fn ($a) => ['id' => $a->id, 'connector' => $a->connector, 'label' => $a->label,
                'enabled' => (bool) $a->enabled, 'since' => $a->created_at]),
            'trades' => DB::table('positions')->where('user_id', $user->id)->orderByDesc('opened_at')->limit(15)
                ->get(['id', 'symbol', 'side', 'volume', 'open_price', 'close_price', 'status', 'profit', 'channel_id', 'opened_at', 'closed_at'])
                ->map(fn ($t) => (array) $t + ['channel' => $titles[$t->channel_id] ?? null]),
            'stats' => [
                'open' => DB::table('positions')->where('user_id', $user->id)->where('status', 'open')->count(),
                'orders_30d' => DB::table('orders')->where('user_id', $user->id)->where('created_at', '>=', now()->subDays(30))->where('status', 'executed')->count(),
                'failed_30d' => DB::table('orders')->where('user_id', $user->id)->where('created_at', '>=', now()->subDays(30))->where('status', 'failed')->count(),
                'profit_30d' => (float) DB::table('positions')->where('user_id', $user->id)->where('closed_at', '>=', now()->subDays(30))->sum('profit'),
            ],
            'audit' => AuditLog::where(fn ($w) => $w->where('actor', $user->id)->orWhere('entity_id', $user->id))->orderByDesc('id')->limit(30)->get(),
        ];
    }

    public function userStatus(Request $req, User $user, Bus $bus)
    {
        $s = $req->validate(['status' => ['required', 'in:active,suspended']])['status'];
        abort_if($user->id === $req->user()->id, 422, 'You cannot suspend yourself.');
        $user->forceFill(['status' => $s])->save();
        if ($s === 'suspended') { // 'active' middleware signs them out on their next request; the engine skips their trades
            $user->forceFill(['remember_token' => null])->save();
        }
        AuditLog::record($req->user()->id, "user_{$s}", 'user', $user->id);
        $bus->publish('config.changed', ['entity' => 'user', 'entity_id' => $user->id]);

        return ['status' => $s];
    }

    /** Pause or resume one customer's copying (their own kill switch). */
    public function userPause(Request $req, User $user, KillSwitch $kill)
    {
        $on = $req->validate(['on' => ['required', 'boolean']])['on'];
        $kill->set($on, $user->id, $req->user()->id);
        $user->forceFill(['kill_switch' => $on])->save();

        return ['paused' => $on];
    }

    public function userRole(Request $req, User $user)
    {
        $role = $req->validate(['role' => ['required', 'in:admin,customer']])['role'];
        abort_if($user->id === $req->user()->id, 422, 'You cannot change your own role.');
        abort_if($user->isSuperadmin(), 422, 'The superadmin\'s role cannot be changed here.');
        $user->forceFill(['role' => $role])->save();
        AuditLog::record($req->user()->id, 'user_role', 'user', $user->id, ['role' => $role]);

        return ['role' => $role];
    }

    /** Give a customer more trial days (support goodwill). */
    public function userTrial(Request $req, User $user)
    {
        $days = $req->validate(['days' => ['required', 'integer', 'min:1', 'max:90']])['days'];
        $b = $user->billing;
        abort_unless($b, 422, 'This customer has no plan yet.');
        abort_if($b->plan_id === 'lifetime', 422, 'This customer is free for life already.');
        $from = $b->current_period_end && $b->current_period_end->isFuture() ? $b->current_period_end : now();
        $b->forceFill(['current_period_end' => $from->copy()->addDays($days),
            'status' => in_array($b->status, ['canceled', 'past_due'], true) ? 'trialing' : $b->status])->save();
        AuditLog::record($req->user()->id, 'trial_extended', 'user', $user->id, ['days' => $days]);

        return ['period_end' => $b->current_period_end];
    }

    public function audit()
    {
        return ['entries' => AuditLog::orderByDesc('id')->limit(200)->get()];
    }
}
