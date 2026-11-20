<?php

namespace App\Http\Controllers;

use App\Models\TelegramAccount;
use App\Pipstream\TelegramLink;
use Illuminate\Http\Request;

/**
 * "Connect Telegram" screens: phone -> code -> (2FA password) -> pick channels.
 * Each call waits a few seconds for the engine's answer so the phone gets an instant result.
 */
class TelegramController extends Controller
{
    public function __construct(private TelegramLink $link) {}

    private function account(Request $r): TelegramAccount
    {
        return $r->user()->telegramAccounts()->orderByDesc('created_at')->first()
            ?? TelegramAccount::create(['user_id' => $r->user()->id, 'status' => 'pending']);
    }

    private function present(TelegramAccount $a): array
    {
        return ['id' => $a->id, 'linked' => $a->isLinked(), 'status' => $a->status, 'phone_hint' => $a->phone_hint,
            'last_error' => $a->last_error];
    }

    public function show(Request $r)
    {
        $a = $r->user()->telegramAccounts()->orderByDesc('created_at')->first();

        return ['account' => $a ? $this->present($a) : null];
    }

    public function phone(Request $r)
    {
        $data = $r->validate(['phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/']],
            ['phone.regex' => 'Use your full number with country code, like +254712345678.']);

        return $this->link->request($this->account($r), 'send_code', ['phone' => $data['phone']]);
    }

    public function code(Request $r)
    {
        $data = $r->validate(['code' => ['required', 'string', 'regex:/^\d{4,8}$/']]);

        return $this->link->request($this->account($r), 'sign_in', ['code' => $data['code']]);
    }

    /** Log in by scanning a QR with the Telegram app (no login code). Poll telegram/requests/{rid}. */
    public function qr(Request $r)
    {
        return $this->link->request($this->account($r), 'qr');
    }

    /** Same login, next delivery method (SMS / call), keeping the code already sent valid. */
    public function resend(Request $r)
    {
        return $this->link->request($this->account($r), 'resend');
    }

    public function password(Request $r)
    {
        $data = $r->validate(['password' => ['required', 'string', 'max:256']]);

        return $this->link->request($this->account($r), 'password', ['password' => $data['password']]);
    }

    public function request(Request $r, string $rid)
    {
        $s = $this->link->status($rid);
        abort_if(isset($s['user_id']) && $s['user_id'] !== $r->user()->id, 404);

        return $s;
    }

    /** The channels this account has joined, newest list first; refreshes from Telegram on demand. */
    public function channels(Request $r)
    {
        $a = $this->account($r);
        abort_unless($a->isLinked(), 409, 'Connect Telegram first.');
        $list = $r->boolean('refresh') ? null : TelegramLink::cachedChannels($a->id);
        if ($list === null) {
            $res = $this->link->request($a, 'list_channels');
            $list = $res['channels'] ?? TelegramLink::cachedChannels($a->id);
            if ($list === null) {
                return ['status' => $res['status'], 'channels' => [], 'request_id' => $res['request_id'] ?? null];
            }
        }
        $followed = $r->user()->id ? \DB::table('subscriptions')->join('channels', 'channels.id', '=', 'subscriptions.channel_id')
            ->where('subscriptions.user_id', $r->user()->id)->pluck('channels.id', 'channels.tg_chat_id') : collect();
        $known = \App\Models\Channel::whereIn('tg_chat_id', array_column($list, 'tg_chat_id'))->get()->keyBy('tg_chat_id');

        return ['status' => 'channels', 'channels' => array_map(fn ($c) => $c + [
            'followed' => isset($followed[$c['tg_chat_id']]),
            'channel_id' => $known[$c['tg_chat_id']]->id ?? null,
            'followers' => isset($known[$c['tg_chat_id']]) ? $known[$c['tg_chat_id']]->subscribers()->count() : 0,
        ], $list)];
    }

    public function disconnect(Request $r)
    {
        $a = $r->user()->telegramAccounts()->orderByDesc('created_at')->firstOrFail();

        return $this->link->request($a, 'logout');
    }
}
