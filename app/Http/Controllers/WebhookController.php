<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\Connected;
use App\Pipstream\Accounts;
use App\Pipstream\Bot\BotSettings;
use App\Pipstream\Bot\BotUpdates;
use App\Pipstream\Bus;
use App\Pipstream\Ids;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    /** Paystack: HMAC-SHA512 of the raw body with the secret key. Idempotent on reference. */
    public function paystack(Request $r, Bus $bus)
    {
        $secret = config('pipstream.paystack_secret');
        $sig = (string) $r->header('x-paystack-signature');
        abort_unless($secret && hash_equals(hash_hmac('sha512', $r->getContent(), $secret), $sig), 401);
        $ev = $r->json()->all();
        if (in_array($ev['event'] ?? null, ['charge.failed', 'invoice.payment_failed'], true)) {
            $uid = $ev['data']['metadata']['user_id'] ?? $ev['data']['subscription']['metadata']['user_id'] ?? null;
            if ($uid && ($u = User::find($uid)) && Cache::add('payfail:'.($ev['data']['reference'] ?? $ev['data']['id'] ?? $uid), 1, now()->addDay())) {
                Accounts::safely(fn () => $u->notify(new \App\Notifications\TradeAlert('payment_failed', 'Your payment didn\'t go through',
                    'Your plan payment failed, so it was not renewed. Try again in PipStream → Plan and billing (card or M-Pesa).')));
            }

            return ['ok' => true];
        }
        if (($ev['event'] ?? null) !== 'charge.success') {
            return ['ok' => true];
        }
        $d = $ev['data'];
        $userId = $d['metadata']['user_id'] ?? null;
        $planId = $d['metadata']['plan_id'] ?? null;
        abort_unless($userId && $planId, 422);
        $fresh = DB::transaction(function () use ($d, $userId, $planId) {
            $inserted = DB::table('invoices')->insertOrIgnore(['id' => Ids::new('inv'), 'user_id' => $userId,
                'amount_cents' => (int) $d['amount'], 'currency' => $d['currency'], 'provider' => 'paystack',
                'provider_ref' => $d['reference'], 'status' => 'paid']);
            if (! $inserted) {
                return false; // replayed webhook
            }
            $sub = BillingSubscription::firstOrNew(['user_id' => $userId]);
            if ($sub->plan_id === 'lifetime') {
                return false; // free for life already: keep the payment on record, never replace the plan
            }
            $from = $sub->current_period_end && $sub->current_period_end->isFuture() ? $sub->current_period_end : now();
            $sub->fill(['plan_id' => $planId, 'status' => 'active', 'provider' => 'paystack',
                'provider_ref' => $d['reference'], 'current_period_end' => $from->copy()->addDays(30)])->save();

            return true;
        });
        if ($fresh) {
            AuditLog::record('paystack', 'payment_received', 'user', $userId, ['reference' => $d['reference']]);
            $bus->publish('billing.changed', ['user_id' => $userId, 'plan_id' => $planId, 'status' => 'active']);
            if ($u = User::find($userId)) {
                Accounts::safely(fn () => $u->notify(new Connected('plan', Plan::find($planId)?->name)));
            }
        }

        return ['ok' => true];
    }

    /**
     * @pip_stream_bot (Telegram Bot API). The secret is in the URL and, when the webhook was set
     * from Admin, also in Telegram's X-Telegram-Bot-Api-Secret-Token header. Always answers 200
     * so Telegram never re-sends an update; each update is handled once (update_id).
     */
    public function telegramBot(Request $r, string $secret, BotUpdates $bot)
    {
        $expected = BotSettings::secret();
        abort_unless($expected && hash_equals($expected, $secret), 404);
        $header = $r->header('X-Telegram-Bot-Api-Secret-Token');
        abort_if($header !== null && ! hash_equals($expected, (string) $header), 404);
        $update = $r->json()->all();
        if (isset($update['update_id']) && ! Cache::add('botupd:'.$update['update_id'], 1, now()->addDay())) {
            return ['ok' => true];
        }
        try {
            $bot->handle($update);
        } catch (\Throwable $e) {
            report($e);
        }

        return ['ok' => true];
    }
}
