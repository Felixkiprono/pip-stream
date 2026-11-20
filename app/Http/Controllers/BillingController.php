<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Pipstream\Ids;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/** Plans and Paystack checkout (cards and M-Pesa). Activation happens in the webhook. */
class BillingController extends Controller
{
    public function show(Request $r)
    {
        $b = $r->user()->billing;

        return [
            'current' => $b ? ['plan' => $b->plan_id, 'plan_name' => $b->plan?->name, 'status' => $b->status,
                'entitled' => $b->isEntitled(), 'until' => $b->current_period_end] : null,
            'plans' => Plan::where('id', '!=', 'lifetime')->orderBy('price_cents')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name,
                'price' => $p->price_cents / 100, 'currency' => $p->currency, 'live' => $p->allow_live,
                'brokers' => $p->max_accounts]),
            'can_pay' => (bool) config('pipstream.paystack_secret'),
        ];
    }

    public function checkout(Request $r)
    {
        $plan = Plan::findOrFail($r->validate(['plan' => ['required', 'string']])['plan']);
        abort_if($plan->price_cents <= 0, 422, 'This plan is free.');
        abort_unless(config('pipstream.paystack_secret'), 503, 'Payments are not set up yet.');
        $ref = Ids::new('pay');
        $res = Http::withToken(config('pipstream.paystack_secret'))->timeout(15)
            ->post('https://api.paystack.co/transaction/initialize', [
                'email' => $r->user()->email, 'amount' => $plan->price_cents, 'currency' => $plan->currency,
                'reference' => $ref, 'channels' => ['card', 'mobile_money'],
                'callback_url' => url('/app/billing?paid=1'),
                'metadata' => ['user_id' => $r->user()->id, 'plan_id' => $plan->id],
            ])->throw()->json();

        return ['url' => $res['data']['authorization_url'], 'reference' => $ref];
    }
}
