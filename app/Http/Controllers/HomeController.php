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
