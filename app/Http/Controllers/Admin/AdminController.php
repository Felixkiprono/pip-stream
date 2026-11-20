<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\ParserProfile;
use App\Models\User;
use App\Pipstream\Bus;
use App\Pipstream\KillSwitch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function users()
    {
        return ['users' => User::orderByDesc('created_at')->limit(200)->get()->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'status' => $u->status,
            'plan' => $u->billing?->plan_id, 'billing' => $u->billing?->status,
            'channels' => DB::table('subscriptions')->where('user_id', $u->id)->count(),
            'telegram' => $u->telegramAccounts()->where('status', 'active')->exists(), 'joined' => $u->created_at])];
    }

    public function audit()
    {
        return ['entries' => AuditLog::orderByDesc('id')->limit(200)->get()];
    }
}
