<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BillingSubscription;
use App\Models\Invoice;
use App\Pipstream\Bus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class WebhookController extends Controller
{
    /** Paystack: HMAC-SHA512 of the raw body with the secret key. Idempotent on reference. */
    public function paystack(Request $r, Bus $bus)
    {
        $secret = config('pipstream.paystack_secret');
        $sig = (string) $r->header('x-paystack-signature');
        abort_unless($secret && hash_equals(hash_hmac('sha512', $r->getContent(), $secret), $sig), 401);
        $ev = $r->json()->all();
        if (($ev['event'] ?? null) !== 'charge.success') {
            return ['ok' => true];
        }
        $d = $ev['data'];
        $userId = $d['metadata']['user_id'] ?? null;
        $planId = $d['metadata']['plan_id'] ?? null;
        abort_unless($userId && $planId, 422);
        $fresh = DB::transaction(function () use ($d, $userId, $planId) {
            $inserted = DB::table('invoices')->insertOrIgnore(['id' => \App\Pipstream\Ids::new('inv'), 'user_id' => $userId,
                'amount_cents' => (int) $d['amount'], 'currency' => $d['currency'], 'provider' => 'paystack',
                'provider_ref' => $d['reference'], 'status' => 'paid']);
            if (! $inserted) {
                return false; // replayed webhook
            }
            $sub = BillingSubscription::firstOrNew(['user_id' => $userId]);
            $from = $sub->current_period_end && $sub->current_period_end->isFuture() ? $sub->current_period_end : now();
            $sub->fill(['plan_id' => $planId, 'status' => 'active', 'provider' => 'paystack',
                'provider_ref' => $d['reference'], 'current_period_end' => $from->copy()->addDays(30)])->save();

            return true;
        });
        if ($fresh) {
            AuditLog::record('paystack', 'payment_received', 'user', $userId, ['reference' => $d['reference']]);
            $bus->publish('billing.changed', ['user_id' => $userId, 'plan_id' => $planId, 'status' => 'active']);
        }

        return ['ok' => true];
    }

    /** PipStream alerts bot: "/start <token>" links the chat to the user who tapped the link. */
    public function telegramBot(Request $r, string $secret)
    {
        abort_unless(config('pipstream.bot_webhook_secret') && hash_equals(config('pipstream.bot_webhook_secret'), $secret), 404);
        $msg = $r->input('message', []);
        $text = trim((string) ($msg['text'] ?? ''));
        $chat = $msg['chat']['id'] ?? null;
        if ($chat && preg_match('/^\/start\s+([A-Za-z0-9]{32})$/', $text, $m) && ($uid = Cache::pull("botlink:{$m[1]}"))) {
            DB::table('notification_prefs')->upsert([['user_id' => $uid, 'channel' => 'telegram_bot', 'address' => (string) $chat]],
                ['user_id', 'channel'], ['address']);
            $this->reply($chat, "You're connected. PipStream will message you here when trades open, change or close.");
        } elseif ($chat && str_starts_with($text, '/start')) {
            $this->reply($chat, 'Open PipStream → Settings → Alerts on Telegram to connect this chat.');
        }

        return ['ok' => true];
    }

    private function reply($chat, string $text): void
    {
        if ($t = config('pipstream.bot_token')) {
            Http::timeout(10)->post("https://api.telegram.org/bot{$t}/sendMessage", ['chat_id' => $chat, 'text' => $text]);
        }
    }
}
