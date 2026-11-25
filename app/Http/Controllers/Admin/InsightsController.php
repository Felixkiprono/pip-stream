<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BrokerController;
use App\Http\Controllers\Controller;
use App\Models\BrokerAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The superadmin's bird's-eye view: who the customers are, how far each got (Telegram,
 * channels, broker, live copying), what they copy, what fails, plans and money.
 * Never returns credentials, Telegram sessions or anything sealed.
 */
class InsightsController extends Controller
{
    public function insights(Request $req)
    {
        $days = (int) ($req->validate(['days' => ['nullable', 'integer', 'in:7,30,90']])['days'] ?? 30);
        $from = now()->startOfDay()->subDays($days - 1);
        $customers = fn () => DB::table('users')->where('role', 'customer');
        $linked = fn ($q) => $q->select('user_id')->from('telegram_accounts')->where('status', 'active')->whereNotNull('session_ciphertext');

        $funnel = [
            ['key' => 'signed_up', 'label' => 'Signed up', 'n' => $customers()->count()],
            ['key' => 'telegram', 'label' => 'Linked Telegram', 'n' => $customers()->whereIn('id', $linked)->count()],
            ['key' => 'following', 'label' => 'Follow a channel', 'n' => $customers()->whereIn('id', fn ($q) => $q->select('user_id')->from('subscriptions'))->count()],
            ['key' => 'broker', 'label' => 'Connected a broker', 'n' => $customers()->whereIn('id', fn ($q) => $q->select('user_id')->from('broker_accounts')->where('connector', '<>', 'paper'))->count()],
            ['key' => 'copied', 'label' => 'Copied a trade', 'n' => $customers()->whereIn('id', fn ($q) => $q->select('user_id')->from('orders')->where('status', 'executed'))->count()],
            ['key' => 'live', 'label' => 'Copying live', 'n' => $customers()->whereIn('id', fn ($q) => $q->select('user_id')->from('copy_rules')->where('enabled', true)->where('mode', 'live'))->count()],
        ];

        $seen = fn ($d) => $customers()->where('last_seen_at', '>=', now()->subDays($d))->count();
        $traders = fn ($since) => DB::table('orders')->where('status', 'executed')->where('created_at', '>=', $since)->distinct()->count('user_id');
        $people = [
            'customers' => $funnel[0]['n'],
            'new' => $customers()->where('created_at', '>=', $from)->count(),
            'seen_1d' => $seen(1), 'seen_7d' => $seen(7), 'seen_30d' => $seen(30),
            'traders_1d' => $traders(now()->subDay()), 'traders_7d' => $traders(now()->subDays(7)), 'traders_window' => $traders($from),
            'suspended' => $customers()->where('status', 'suspended')->count(),
            'paused' => $customers()->where('kill_switch', true)->count(),
            'admins' => DB::table('users')->whereIn('role', ['admin', 'superadmin'])->count(),
        ];

        $series = collect(DB::select(<<<'SQL'
            with b as (select generate_series(date_trunc('day', :f::timestamptz), date_trunc('day', now()), interval '1 day') as t)
            select b.t,
              (select count(*) from users where role = 'customer' and date_trunc('day', created_at) = b.t) as signups,
              (select count(distinct user_id) from orders where status = 'executed' and date_trunc('day', created_at) = b.t) as traders,
              (select count(*) from orders where status = 'executed' and date_trunc('day', created_at) = b.t) as fills,
              (select count(*) from orders where status <> 'executed' and date_trunc('day', created_at) = b.t) as failed,
              (select count(*) from signals where status = 'parsed' and date_trunc('day', created_at) = b.t) as signals
            from b order by b.t
            SQL, ['f' => $from->toIso8601String()]))->map(fn ($x) => ['t' => $x->t, 'signups' => (int) $x->signups,
            'traders' => (int) $x->traders, 'fills' => (int) $x->fills, 'failed' => (int) $x->failed, 'signals' => (int) $x->signals]);

        $orders = DB::table('orders')->where('created_at', '>=', $from)
            ->selectRaw("count(*) filter (where status = 'executed') as fills, count(*) filter (where status <> 'executed') as failed,
                count(distinct user_id) as users, percentile_cont(0.5) within group (order by latency_ms) filter (where status = 'executed' and action = 'open') as p50")
            ->first();
        $signals = DB::table('signals')->where('created_at', '>=', $from)
            ->selectRaw("count(*) filter (where status = 'parsed') as parsed, count(*) filter (where status = 'rejected') as rejected")->first();

        $plans = DB::table('users')->leftJoin('billing_subscriptions as b', 'b.user_id', '=', 'users.id')->leftJoin('plans', 'plans.id', '=', 'b.plan_id')
            ->where('users.role', 'customer')
            ->selectRaw("coalesce(b.plan_id, 'none') as plan, coalesce(plans.name, 'No plan') as name, coalesce(b.status, 'none') as status, count(*) as n")
            ->groupByRaw('1, 2, 3')->orderBy('plan')->get()
            ->map(fn ($p) => ['plan' => $p->plan, 'name' => $p->name, 'status' => $p->status, 'n' => (int) $p->n]);

        $paying = DB::table('billing_subscriptions as b')->join('plans', 'plans.id', '=', 'b.plan_id')
            ->where('b.status', 'active')->where('plans.price_cents', '>', 0)->where(fn ($w) => $w->whereNull('b.current_period_end')->orWhere('b.current_period_end', '>', now()))
            ->selectRaw('plans.currency, count(*) as n, sum(plans.price_cents) as cents')->groupBy('plans.currency')->get();
        $paid = fn ($since) => DB::table('invoices')->where('status', 'paid')->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->selectRaw('currency, count(*) as n, sum(amount_cents) as cents, count(distinct user_id) as payers')->groupBy('currency')->get()
            ->map(fn ($r) => ['currency' => strtoupper($r->currency), 'n' => (int) $r->n, 'cents' => (int) $r->cents, 'payers' => (int) $r->payers]);

        $slots = (int) config('pipstream.founding_slots');
        $fills = DB::table('orders')->where('status', 'executed')->selectRaw('user_id, count(*) n')->groupBy('user_id');
        $founding = DB::table('users')->whereNotNull('founding_number')->leftJoinSub($fills, 'f', 'f.user_id', '=', 'users.id')
            ->orderBy('founding_number')
            ->get(['users.id', 'users.name', 'users.email', 'users.founding_number', 'users.created_at', 'users.last_seen_at', 'f.n'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'number' => (int) $u->founding_number,
                'joined' => $u->created_at, 'last_seen' => $u->last_seen_at, 'fills' => (int) ($u->n ?? 0)]);

        $brokers = DB::table('broker_accounts')->selectRaw('connector, count(*) as accounts, count(*) filter (where enabled) as enabled, count(distinct user_id) as owners')
            ->groupBy('connector')->orderByDesc('accounts')->get()
            ->map(fn ($b) => ['connector' => $b->connector, 'accounts' => (int) $b->accounts, 'enabled' => (int) $b->enabled, 'owners' => (int) $b->owners]);
        $modes = DB::table('copy_rules')->where('enabled', true)->selectRaw('mode, count(*) as n, count(distinct user_id) as users')->groupBy('mode')->get()
            ->map(fn ($m) => ['mode' => $m->mode, 'rules' => (int) $m->n, 'users' => (int) $m->users]);

        $failures = DB::table('orders')->where('created_at', '>=', $from)->where('status', '<>', 'executed')
            ->selectRaw("left(coalesce(nullif(error, ''), status), 140) as reason, count(*) as n, count(distinct user_id) as users, max(created_at) as last")
            ->groupBy('reason')->orderByDesc('n')->limit(10)->get()
            ->map(fn ($f) => ['reason' => $f->reason, 'n' => (int) $f->n, 'users' => (int) $f->users, 'last' => $f->last]);
        $byConnector = DB::table('orders')->join('broker_accounts', 'broker_accounts.id', '=', 'orders.broker_account_id')->where('orders.created_at', '>=', $from)
            ->selectRaw("broker_accounts.connector, count(*) filter (where orders.status = 'executed') as fills, count(*) filter (where orders.status <> 'executed') as failed")
            ->groupBy('broker_accounts.connector')->orderByDesc('fills')->get()
            ->map(fn ($c) => ['connector' => $c->connector, 'fills' => (int) $c->fills, 'failed' => (int) $c->failed]);

        $profit = DB::table('positions')->where('closed_at', '>=', $from)->selectRaw('user_id, sum(profit) as p')->groupBy('user_id');
        $top = DB::table('orders')->join('users', 'users.id', '=', 'orders.user_id')->leftJoinSub($profit, 'pr', 'pr.user_id', '=', 'orders.user_id')
            ->where('orders.created_at', '>=', $from)
            ->groupBy('users.id', 'users.email', 'users.name', 'pr.p')
            ->selectRaw("users.id, users.email, users.name, count(*) filter (where orders.status = 'executed') as fills, count(*) filter (where orders.status <> 'executed') as failed, pr.p as profit, max(orders.created_at) as last")
            ->orderByDesc('fills')->limit(10)->get()
            ->map(fn ($u) => ['id' => $u->id, 'email' => $u->email, 'name' => $u->name, 'fills' => (int) $u->fills, 'failed' => (int) $u->failed,
                'profit' => $u->profit !== null ? round((float) $u->profit, 2) : null, 'last' => $u->last]);

        $channels = DB::table('channels')->leftJoin('subscriptions', 'subscriptions.channel_id', '=', 'channels.id')
            ->groupBy('channels.id', 'channels.title', 'channels.active')
            ->selectRaw('channels.id, channels.title, channels.active, count(subscriptions.user_id) as followers')
            ->orderByDesc('followers')->limit(10)->get()
            ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'active' => (bool) $c->active, 'followers' => (int) $c->followers]);

        return [
            'days' => $days,
            'funnel' => $funnel,
            'people' => $people,
            'series' => $series,
            'window' => ['fills' => (int) $orders->fills, 'failed' => (int) $orders->failed, 'users' => (int) $orders->users,
                'p50' => $orders->p50 !== null ? (int) round($orders->p50) : null,
                'parsed' => (int) $signals->parsed, 'rejected' => (int) $signals->rejected],
            'plans' => $plans,
            'revenue' => ['window' => $paid($from), 'all_time' => $paid(null),
                'paying_now' => $paying->map(fn ($r) => ['currency' => strtoupper($r->currency), 'n' => (int) $r->n, 'cents' => (int) $r->cents])],
            'founding' => ['slots' => $slots, 'taken' => $founding->count(), 'members' => $founding],
            'brokers' => $brokers,
            'modes' => $modes,
            'failures' => $failures,
            'by_connector' => $byConnector,
            'top_customers' => $top,
            'top_channels' => $channels,
        ];
    }

    /** Every broker account across all customers, with its owner and how it is doing. */
    public function brokers(Request $req, BrokerController $present)
    {
        $d = $req->validate(['q' => ['nullable', 'string', 'max:100'], 'connector' => ['nullable', 'string', 'max:40']]);
        $q = BrokerAccount::query()->join('users', 'users.id', '=', 'broker_accounts.user_id')
            ->select('broker_accounts.*', 'users.email as owner_email', 'users.name as owner_name')
            ->orderByDesc('broker_accounts.created_at');
        if ($s = trim($d['q'] ?? '')) {
            $q->where(fn ($w) => $w->where('users.email', 'ilike', "%{$s}%")->orWhere('broker_accounts.label', 'ilike', "%{$s}%")->orWhere('broker_accounts.id', $s));
        }
        if (! empty($d['connector'])) {
            $q->where('broker_accounts.connector', $d['connector']);
        }
        $accounts = $q->limit(300)->get();
        $ids = $accounts->pluck('id');
        $since = now()->subDays(30);
        $ord = DB::table('orders')->whereIn('broker_account_id', $ids)->where('created_at', '>=', $since)
            ->selectRaw("broker_account_id, count(*) filter (where status = 'executed') as fills, count(*) filter (where status <> 'executed') as failed, max(created_at) as last")
            ->groupBy('broker_account_id')->get()->keyBy('broker_account_id');
        $rules = DB::table('copy_rules')->whereIn('broker_account_id', $ids)->where('enabled', true)
            ->selectRaw("broker_account_id, count(*) as n, count(*) filter (where mode = 'live') as live")->groupBy('broker_account_id')->get()->keyBy('broker_account_id');
        $open = DB::table('positions')->whereIn('broker_account_id', $ids)->where('status', 'open')
            ->selectRaw('broker_account_id, count(*) as n')->groupBy('broker_account_id')->pluck('n', 'broker_account_id');

        return [
            'connectors' => DB::table('broker_accounts')->distinct()->orderBy('connector')->pluck('connector'),
            'accounts' => $accounts->map(fn ($a) => $present->present($a) + [
                'owner' => ['id' => $a->user_id, 'email' => $a->owner_email, 'name' => $a->owner_name],
                'since' => $a->created_at,
                'rules' => (int) ($rules[$a->id]->n ?? 0), 'live_rules' => (int) ($rules[$a->id]->live ?? 0),
                'fills_30d' => (int) ($ord[$a->id]->fills ?? 0), 'failed_30d' => (int) ($ord[$a->id]->failed ?? 0),
                'last_order' => $ord[$a->id]->last ?? null, 'open' => (int) ($open[$a->id] ?? 0),
            ]),
        ];
    }

    /** Copy activity across all customers: orders (fills and failures) or positions. */
    public function activity(Request $req)
    {
        $d = $req->validate(['view' => ['nullable', 'in:orders,positions'], 'status' => ['nullable', 'in:executed,failed,rejected,open,closed'],
            'q' => ['nullable', 'string', 'max:100'], 'user' => ['nullable', 'string', 'max:40']]);
        $view = $d['view'] ?? 'orders';
        $titles = DB::table('channels')->pluck('title', 'id');
        $s = trim($d['q'] ?? '');

        if ($view === 'positions') {
            $q = DB::table('positions')->join('users', 'users.id', '=', 'positions.user_id')
                ->join('broker_accounts', 'broker_accounts.id', '=', 'positions.broker_account_id')
                ->orderByDesc('positions.opened_at');
            if (! empty($d['status']) && in_array($d['status'], ['open', 'closed'], true)) {
                $q->where('positions.status', $d['status']);
            }
            if ($s !== '') {
                $q->where(fn ($w) => $w->where('users.email', 'ilike', "%{$s}%")->orWhere('positions.symbol', 'ilike', "%{$s}%"));
            }
            if (! empty($d['user'])) {
                $q->where('positions.user_id', $d['user']);
            }
            $rows = $q->limit(200)->get(['positions.id', 'positions.user_id', 'users.email', 'positions.symbol', 'positions.side', 'positions.volume',
                'positions.open_price', 'positions.close_price', 'positions.sl', 'positions.tp', 'positions.status', 'positions.profit', 'positions.ticket',
                'positions.channel_id', 'positions.opened_at', 'positions.closed_at', 'broker_accounts.connector', 'broker_accounts.label'])
                ->map(fn ($p) => $this->numbers((array) $p, ['volume', 'open_price', 'close_price', 'sl', 'tp', 'profit']) + ['channel' => $titles[$p->channel_id] ?? null]);
        } else {
            $q = DB::table('orders')->join('users', 'users.id', '=', 'orders.user_id')
                ->join('broker_accounts', 'broker_accounts.id', '=', 'orders.broker_account_id')
                ->leftJoin('trade_intents', 'trade_intents.id', '=', 'orders.intent_id')
                ->leftJoin('signals', 'signals.id', '=', 'trade_intents.signal_id')
                ->orderByDesc('orders.created_at');
            if (! empty($d['status'])) {
                $d['status'] === 'failed' ? $q->where('orders.status', '<>', 'executed') : $q->where('orders.status', $d['status']);
            }
            if ($s !== '') {
                $q->where(fn ($w) => $w->where('users.email', 'ilike', "%{$s}%")->orWhere('orders.symbol', 'ilike', "%{$s}%"));
            }
            if (! empty($d['user'])) {
                $q->where('orders.user_id', $d['user']);
            }
            $rows = $q->limit(200)->get(['orders.id', 'orders.user_id', 'users.email', 'orders.action', 'orders.status', 'orders.symbol', 'orders.side',
                'orders.volume', 'orders.price', 'orders.ticket', 'orders.error', 'orders.latency_ms', 'orders.created_at',
                'broker_accounts.connector', 'broker_accounts.label', 'signals.channel_id'])
                ->map(fn ($o) => $this->numbers((array) $o, ['volume', 'price', 'latency_ms']) + ['channel' => $titles[$o->channel_id] ?? null]);
        }

        $day = now()->subDay();

        return [
            'view' => $view,
            'rows' => $rows,
            'summary' => [
                'fills_24h' => DB::table('orders')->where('created_at', '>=', $day)->where('status', 'executed')->count(),
                'failed_24h' => DB::table('orders')->where('created_at', '>=', $day)->where('status', '<>', 'executed')->count(),
                'open' => DB::table('positions')->where('status', 'open')->count(),
                'profit_24h' => round((float) DB::table('positions')->where('closed_at', '>=', $day)->sum('profit'), 2),
            ],
        ];
    }

    /** Postgres doubles can arrive as strings: send numbers. */
    private function numbers(array $row, array $keys): array
    {
        foreach ($keys as $k) {
            $row[$k] = $row[$k] === null ? null : +$row[$k];
        }

        return $row;
    }
}
