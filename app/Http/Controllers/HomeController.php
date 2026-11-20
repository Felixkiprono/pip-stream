<?php

namespace App\Http\Controllers;

use App\Models\Position;
use App\Pipstream\Accounts;
use App\Pipstream\KillSwitch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function home(Request $r, KillSwitch $kill, Accounts $accounts)
    {
        $u = $r->user();
        $open = Position::where('user_id', $u->id)->where('status', 'open')->orderByDesc('opened_at')->limit(20)->get();
        $today = DB::table('orders')->where('user_id', $u->id)->where('created_at', '>=', now()->startOfDay())
            ->selectRaw("count(*) filter (where action='open' and status='executed') as opened, count(*) filter (where status='failed') as failed")
            ->first();
        $closedToday = Position::where('user_id', $u->id)->where('status', 'closed')->where('closed_at', '>=', now()->startOfDay())
            ->sum('profit');
        $chanTitles = DB::table('channels')->pluck('title', 'id');
        $signals = DB::table('signals')->join('subscriptions', 'subscriptions.channel_id', '=', 'signals.channel_id')
            ->where('subscriptions.user_id', $u->id)->where('signals.status', 'parsed')
            ->orderByDesc('signals.created_at')->limit(8)
            ->get(['signals.id', 'signals.channel_id', 'signals.kind', 'signals.symbol', 'signals.side', 'signals.created_at', 'signals.payload']);

        return [
            'paused' => $kill->isOn($u->id),
            'global_pause' => $kill->isOn(null),
            'onboarding' => $accounts->onboarding($u),
            'telegram' => optional($u->telegramAccounts()->orderByDesc('created_at')->first(), fn ($a) => ['linked' => $a->isLinked(), 'status' => $a->status]),
            'today' => ['opened' => (int) $today->opened, 'failed' => (int) $today->failed, 'closed_profit' => (float) $closedToday],
            'positions' => $open->map(fn ($p) => [
                'id' => $p->id, 'ticket' => $p->ticket, 'symbol' => $p->symbol, 'side' => $p->side, 'volume' => $p->volume,
                'open_price' => $p->open_price, 'sl' => $p->sl, 'tp' => $p->tp, 'tp_index' => $p->tp_index,
                'channel' => $chanTitles[$p->channel_id] ?? null, 'opened_at' => $p->opened_at, 'profit' => $p->profit]),
            'signals' => $signals->map(fn ($s) => ['id' => $s->id, 'channel' => $chanTitles[$s->channel_id] ?? null,
                'kind' => $s->kind, 'symbol' => $s->symbol, 'side' => $s->side, 'at' => $s->created_at,
                'summary' => self::summary(json_decode($s->payload, true))]),
            'unread' => $u->unreadNotifications()->count(),
        ];
    }

    private static function summary(array $p): string
    {
        return match ($p['kind'] ?? null) {
            'open' => strtoupper($p['side'] ?? '').' '.$p['symbol'].(isset($p['sl']) ? ' · SL '.$p['sl'] : '').
                (! empty($p['tps']) ? ' · TP '.implode(' / ', $p['tps']) : ''),
            'modify' => ($p['move_sl_to_entry'] ?? false) ? 'Move SL to entry' : 'Update SL/TP',
            'close' => 'Close '.(($p['close_fraction'] ?? 1) < 1 ? round($p['close_fraction'] * 100).'%' : 'all'),
            'options' => strtoupper($p['options']['contract'] ?? '').' '.$p['symbol'].' '.($p['options']['duration'] ?? '').($p['options']['duration_unit'] ?? ''),
            default => '',
        };
    }

    /**
     * Live updates: a fingerprint of everything on the customer's screens (signals from their
     * channels, their positions, orders and alerts). The app polls this every couple of seconds
     * and refetches only when it changes, so new signals and trades show without a refresh.
     */
    public function pulse(Request $r)
    {
        $id = $r->user()->id;
        $row = DB::selectOne(<<<'SQL'
            select
              (select max(s.created_at) from signals s join subscriptions sub on sub.channel_id = s.channel_id
                 where sub.user_id = :u1) as sig,
              (select count(*) || ':' || coalesce(max(opened_at)::text, '') || ':' || coalesce(max(closed_at)::text, '')
                 || ':' || coalesce(sum(coalesce(sl, 0) + coalesce(tp, 0) + coalesce(volume, 0) + coalesce(profit, 0)), 0)
                 from positions where user_id = :u2) as pos,
              (select max(created_at) from orders where user_id = :u3) as ord,
              (select count(*) || ':' || coalesce(max(created_at)::text, '') || ':' || count(read_at)
                 from notifications where notifiable_id = :u4) as al
            SQL, ['u1' => $id, 'u2' => $id, 'u3' => $id, 'u4' => $id]);

        return ['v' => md5(json_encode($row))];
    }

    /** Charts and numbers for the customer's own activity (last N days, their channels only). */
    public function stats(Request $r)
    {
        $days = (int) ($r->validate(['days' => ['nullable', 'integer', 'in:7,14,30,90']])['days'] ?? 14);
        $u = $r->user()->id;
        $from = now()->startOfDay()->subDays($days - 1);
        $series = collect(DB::select(<<<'SQL'
            with d as (select generate_series(:from1::date, current_date, interval '1 day')::date as day)
            select d.day,
              (select count(*) from signals s join subscriptions sub on sub.channel_id = s.channel_id and sub.user_id = :u1
                 where s.status = 'parsed' and s.created_at::date = d.day) as signals,
              (select count(*) from signals s join subscriptions sub on sub.channel_id = s.channel_id and sub.user_id = :u2
                 where s.status = 'rejected' and s.created_at::date = d.day) as ignored,
              (select count(*) from positions p where p.user_id = :u3 and p.opened_at::date = d.day) as opened,
              (select coalesce(sum(profit), 0) from positions p where p.user_id = :u4 and p.status = 'closed' and p.closed_at::date = d.day) as pnl
            from d order by d.day
            SQL, ['from1' => $from->toDateString(), 'u1' => $u, 'u2' => $u, 'u3' => $u, 'u4' => $u]))
            ->map(fn ($x) => ['day' => $x->day, 'signals' => (int) $x->signals, 'ignored' => (int) $x->ignored,
                'opened' => (int) $x->opened, 'pnl' => round((float) $x->pnl, 2)]);

        $closed = DB::table('positions')->where('user_id', $u)->where('status', 'closed');
        $t = DB::table('positions')->where('user_id', $u)->selectRaw(<<<'SQL'
            count(*) as total, count(*) filter (where status = 'open') as open,
            count(*) filter (where status = 'closed') as closed,
            count(*) filter (where status = 'closed' and profit > 0) as wins,
            count(*) filter (where status = 'closed' and profit < 0) as losses,
            coalesce(sum(profit) filter (where status = 'closed'), 0) as pnl,
            max(profit) filter (where status = 'closed') as best, min(profit) filter (where status = 'closed') as worst
            SQL)->first();
        $latency = DB::table('orders')->where('user_id', $u)->where('action', 'open')->where('status', 'executed')
            ->where('created_at', '>=', $from)->selectRaw('percentile_cont(0.5) within group (order by latency_ms) as p')->value('p');

        $channels = DB::table('subscriptions')->join('channels', 'channels.id', '=', 'subscriptions.channel_id')
            ->where('subscriptions.user_id', $u)->get(['channels.id', 'channels.title'])
            ->map(function ($c) use ($u, $from) {
                $s = DB::table('signals')->where('channel_id', $c->id)->where('created_at', '>=', $from)
                    ->selectRaw("count(*) filter (where status='parsed') as parsed, count(*) filter (where status='rejected') as ignored")->first();
                $p = DB::table('positions')->where('user_id', $u)->where('channel_id', $c->id)->where('opened_at', '>=', $from)
                    ->selectRaw("count(*) as trades, coalesce(sum(profit) filter (where status='closed'), 0) as pnl")->first();

                return ['id' => $c->id, 'title' => $c->title, 'signals' => (int) $s->parsed, 'ignored' => (int) $s->ignored,
                    'trades' => (int) $p->trades, 'pnl' => round((float) $p->pnl, 2)];
            })->sortByDesc('signals')->values();

        $symbols = DB::table('positions')->where('user_id', $u)->where('opened_at', '>=', $from)
            ->selectRaw("symbol, count(*) as trades, coalesce(sum(profit) filter (where status='closed'), 0) as pnl")
            ->groupBy('symbol')->orderByDesc('trades')->limit(6)->get()
            ->map(fn ($x) => ['symbol' => $x->symbol, 'trades' => (int) $x->trades, 'pnl' => round((float) $x->pnl, 2)]);

        // cumulative closed P/L, all time, for the equity curve
        $curve = $closed->clone()->orderBy('closed_at')->get(['closed_at', 'profit'])
            ->reduce(function ($acc, $p) {
                $last = $acc ? end($acc)['v'] : 0;
                $acc[] = ['t' => $p->closed_at, 'v' => round($last + (float) $p->profit, 2)];

                return $acc;
            }, []);

        return [
            'days' => $days,
            'series' => $series,
            'totals' => ['trades' => (int) $t->total, 'open' => (int) $t->open, 'closed' => (int) $t->closed,
                'wins' => (int) $t->wins, 'losses' => (int) $t->losses, 'pnl' => round((float) $t->pnl, 2),
                'best' => $t->best !== null ? (float) $t->best : null, 'worst' => $t->worst !== null ? (float) $t->worst : null,
                'win_rate' => ($t->wins + $t->losses) ? round(100 * $t->wins / ($t->wins + $t->losses)) : null,
                'latency_ms' => $latency !== null ? (int) round($latency) : null],
            'channels' => $channels,
            'symbols' => $symbols,
            'curve' => array_slice($curve, -200),
        ];
    }

    public function trades(Request $r)
    {
        $u = $r->user();
        $chanTitles = DB::table('channels')->pluck('title', 'id');
        $pos = Position::where('user_id', $u->id)->orderByDesc('opened_at')->paginate(30);

        return ['trades' => collect($pos->items())->map(fn ($p) => [
            'id' => $p->id, 'ticket' => $p->ticket, 'symbol' => $p->symbol, 'side' => $p->side, 'volume' => $p->volume,
            'open_price' => $p->open_price, 'close_price' => $p->close_price, 'sl' => $p->sl, 'tp' => $p->tp,
            'status' => $p->status, 'profit' => $p->profit, 'channel' => $chanTitles[$p->channel_id] ?? null,
            'opened_at' => $p->opened_at, 'closed_at' => $p->closed_at]), 'next' => $pos->hasMorePages() ? $pos->currentPage() + 1 : null];
    }

    public function alerts(Request $r)
    {
        return ['alerts' => $r->user()->notifications()->limit(50)->get()->map(fn ($n) => $n->data + [
            'id' => $n->id, 'read' => $n->read_at !== null, 'at' => $n->created_at])];
    }

    public function markRead(Request $r)
    {
        $r->user()->unreadNotifications->markAsRead();

        return ['ok' => true];
    }

    public function pause(Request $r, KillSwitch $kill)
    {
        $on = $r->validate(['on' => ['required', 'boolean']])['on'];
        $kill->set($on, $r->user()->id, $r->user()->id);
        $r->user()->forceFill(['kill_switch' => $on])->save();

        return ['paused' => $on];
    }
}
