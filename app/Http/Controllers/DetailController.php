<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One signal's full story for the customer who copied it: the Telegram post, what the parser
 * understood, every step with timings, the trades it opened and the follow-ups that changed
 * them. Opened from an alert, a trade or a signal row. Only the customer's own trades are shown.
 */
class DetailController extends Controller
{
    public function alert(Request $r, string $id)
    {
        $n = $r->user()->notifications()->where('id', $id)->firstOrFail();
        if (! $n->read_at) {
            $n->markAsRead();
        }
        $d = $n->data;
        $signalId = $d['signal_id'] ?? null;
        if (! $signalId && ! empty($d['ticket'])) { // alerts saved before signal ids were stored
            $signalId = DB::table('positions')->where('user_id', $r->user()->id)->where('ticket', $d['ticket'])
                ->where('symbol', $d['symbol'] ?? '')->where('opened_at', '<=', $n->created_at->addMinute())
                ->orderByDesc('opened_at')->value('signal_id');
        }

        return ['alert' => $d + ['id' => $n->id, 'at' => $n->created_at],
            'detail' => $signalId ? $this->story($r->user()->id, $signalId) : null];
    }

    public function position(Request $r, string $position)
    {
        $p = DB::table('positions')->where('id', $position)->where('user_id', $r->user()->id)->first();
        abort_unless($p, 404);

        return ['detail' => $this->story($r->user()->id, $p->signal_id), 'focus' => $p->id];
    }

    public function signal(Request $r, string $signal)
    {
        $s = DB::table('signals')->join('subscriptions', 'subscriptions.channel_id', '=', 'signals.channel_id')
            ->where('subscriptions.user_id', $r->user()->id)->where('signals.id', $signal)->exists()
            || DB::table('positions')->where('user_id', $r->user()->id)->where('signal_id', $signal)->exists();
        abort_unless($s, 404);

        return ['detail' => $this->story($r->user()->id, $signal)];
    }

    private function story(string $userId, string $signalId): ?array
    {
        $s = DB::table('signals')->where('id', $signalId)->first();
        if (! $s) {
            return null;
        }
        $payload = json_decode($s->payload, true) ?: [];
        $channel = DB::table('channels')->where('id', $s->channel_id)->first(['title', 'username']);

        // the original post, plus later edits of it (same message, higher edit_version)
        $versions = DB::table('signals')->where('channel_id', $s->channel_id)->where('message_id', $s->message_id)
            ->orderBy('edit_version')->get(['id', 'edit_version', 'status', 'confidence', 'created_at']);
        $signalIds = $versions->pluck('id')->all();

        // follow-ups: replies to this post that the parser read as modify/close
        $follow = DB::table('signals')->where('channel_id', $s->channel_id)->where('reply_to_id', $s->message_id)
            ->orderBy('created_at')->get(['id', 'kind', 'status', 'payload', 'created_at', 'message_id']);
        $allIds = array_merge($signalIds, $follow->pluck('id')->all());

        $intents = DB::table('trade_intents')->where('user_id', $userId)->whereIn('signal_id', $allIds)->orderBy('created_at')
            ->get(['id', 'signal_id', 'action', 'created_at', 'payload']);
        $orders = DB::table('orders')->where('user_id', $userId)->whereIn('intent_id', $intents->pluck('id'))->orderBy('created_at')
            ->get(['id', 'intent_id', 'action', 'status', 'ticket', 'symbol', 'side', 'volume', 'price', 'error', 'latency_ms', 'created_at']);
        $intentSignal = $intents->pluck('signal_id', 'id');
        $positions = DB::table('positions')->where('user_id', $userId)->whereIn('signal_id', $signalIds)->orderBy('tp_index')
            ->get(['id', 'ticket', 'symbol', 'side', 'volume', 'open_price', 'sl', 'tp', 'tp_index', 'status', 'close_price', 'profit', 'opened_at', 'closed_at', 'broker_account_id']);
        $broker = $positions->first()?->broker_account_id
            ?? ($intents->first() ? (json_decode($intents->first()->payload, true)['broker_account_id'] ?? null) : null);
        $b = $broker ? DB::table('broker_accounts')->where('id', $broker)->first(['connector', 'label']) : null;
        $skips = DB::table('notifications')->where('notifiable_id', $userId)
            ->whereRaw("data->>'kind' = 'signal_skipped'")->whereRaw("data->>'signal_id' = any(?)", ['{'.implode(',', $allIds).'}'])
            ->get(['data', 'created_at'])->map(fn ($n) => ['text' => json_decode($n->data, true)['body'] ?? '', 'at' => $n->created_at]);

        $posted = $payload['source']['posted_at'] ?? null;
        $firstOrder = $orders->first();
        $timeline = array_values(array_filter([
            $posted ? ['step' => 'posted', 'label' => 'Posted in '.($channel->title ?? 'the channel'), 'at' => $posted] : null,
            ['step' => 'parsed', 'label' => 'Read and understood by PipStream', 'at' => $s->created_at],
            $intents->first() ? ['step' => 'planned', 'label' => 'Trade sized and approved ('.$intents->where('signal_id', $s->id)->count().' leg'.($intents->where('signal_id', $s->id)->count() === 1 ? '' : 's').')', 'at' => $intents->first()->created_at] : null,
            $firstOrder ? ['step' => $firstOrder->status === 'executed' ? 'executed' : 'failed',
                'label' => $firstOrder->status === 'executed' ? 'Order filled at your broker' : 'Order failed: '.($firstOrder->error ?? 'refused'),
                'at' => $firstOrder->created_at, 'ms' => $firstOrder->latency_ms] : null,
            ...$follow->map(fn ($f) => ['step' => 'followup', 'label' => 'Follow-up: '.self::followText(json_decode($f->payload, true) ?: []), 'at' => $f->created_at])->all(),
            ...$positions->whereNotNull('closed_at')->map(fn ($p) => ['step' => 'closed', 'label' => "Trade #{$p->ticket} closed".($p->profit !== null ? ' · '.number_format($p->profit, 2) : ''), 'at' => $p->closed_at])->values()->all(),
            ...$skips->map(fn ($k) => ['step' => 'skipped', 'label' => $k['text'], 'at' => $k['at']])->all(),
        ]));
        $timeline = array_map(fn ($e) => ['at' => \Illuminate\Support\Carbon::parse($e['at'])->utc()->format('Y-m-d\TH:i:s.v\Z')] + $e, $timeline);
        usort($timeline, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return [
            'signal' => [
                'id' => $s->id, 'status' => $s->status, 'kind' => $s->kind, 'symbol' => $s->symbol, 'side' => $s->side,
                'confidence' => $s->confidence !== null ? (float) $s->confidence : null, 'reason' => $s->reason,
                'entry' => $payload['entry'] ?? null, 'sl' => $payload['sl'] ?? null, 'tps' => $payload['tps'] ?? [],
                'raw_text' => $payload['raw_text'] ?? '', 'issues' => $payload['issues'] ?? [],
                'message_id' => $s->message_id, 'edits' => max(0, $versions->count() - 1), 'posted_at' => $posted, 'parsed_at' => $s->created_at,
            ],
            'channel' => ['id' => $s->channel_id, 'title' => $channel->title ?? null, 'username' => $channel->username ?? null],
            'broker' => $b ? ['connector' => $b->connector, 'label' => $b->label] : null,
            // closing zeroes a position's volume; show the size it was opened with
            'positions' => $positions->map(fn ($p) => ['volume' => (float) $p->volume ?: (float) ($orders->first(fn ($o) => $o->action === 'open' && $o->ticket === $p->ticket)?->volume ?? 0)] + (array) $p),
            'orders' => $orders->map(fn ($o) => (array) $o + ['source' => ($intentSignal[$o->intent_id] ?? null) === $s->id || in_array($intentSignal[$o->intent_id] ?? null, $signalIds, true) ? 'signal' : 'followup']),
            'followups' => $follow->map(fn ($f) => ['id' => $f->id, 'text' => self::followText(json_decode($f->payload, true) ?: []),
                'raw_text' => (json_decode($f->payload, true) ?: [])['raw_text'] ?? '', 'status' => $f->status, 'at' => $f->created_at]),
            'timeline' => $timeline,
            'totals' => ['legs' => $positions->count(), 'open' => $positions->where('status', 'open')->count(),
                'profit' => round((float) $positions->sum('profit'), 2), 'volume' => round((float) $positions->sum('volume'), 2),
                'latency_ms' => $firstOrder?->latency_ms],
        ];
    }

    private static function followText(array $p): string
    {
        return match ($p['kind'] ?? null) {
            'modify' => ($p['move_sl_to_entry'] ?? false) ? 'move stop-loss to entry'
                : (trim((isset($p['new_sl']) ? 'new SL '.$p['new_sl'].' ' : '').(isset($p['new_tp']) ? 'new TP '.$p['new_tp'] : '')) ?: 'update SL/TP'),
            'close' => 'close '.(($p['close_fraction'] ?? 1) < 1 ? round($p['close_fraction'] * 100).'%' : 'all'),
            default => (string) ($p['kind'] ?? 'post'),
        };
    }
}
