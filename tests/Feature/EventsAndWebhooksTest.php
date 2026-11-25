<?php

namespace Tests\Feature;

use App\Pipstream\Bus;
use App\Pipstream\EventHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Tests\TestCase;

class EventsAndWebhooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_laravel_cannot_publish_trade_intents(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(Bus::class)->publish('trade.intent', []);
    }

    public function test_consumer_turns_engine_events_into_alerts(): void
    {
        $u = $this->customer();
        $r = Redis::connection('streams')->client();
        $key = config('pipstream.stream_prefix').':stream:trade.executed';
        $ev = ['event_id' => 'evt_1', 'topic' => 'trade.executed', 'trace_id' => 'tr_1', 'occurred_at' => now()->toIso8601String(),
            'producer' => 'executor', 'payload' => ['intent_id' => 'int_1', 'user_id' => $u->id, 'broker_account_id' => 'ba_x',
                'connector' => 'paper', 'mode' => 'paper', 'action' => 'open', 'status' => 'executed', 'signal_id' => 's',
                'channel_id' => 'ch_x', 'source_message_id' => 1, 'symbol' => 'XAUUSD', 'side' => 'buy', 'ticket' => '1001',
                'volume' => 0.06, 'price' => 2349.0, 'sl' => 2340.0, 'tp' => 2360.0]];
        $r->xAdd($key, '*', ['e' => json_encode($ev)]);
        $r->xAdd($key, '*', ['e' => 'not json']);
        $this->artisan('pipstream:consume', ['--once' => true])->assertSuccessful();

        $n = $u->notifications()->first();
        $this->assertSame('Bought 0.06 XAUUSD (practice)', $n->data['title']);
        $this->assertStringContainsString('@ 2349 · SL 2340 · TP 2360', $n->data['body']);
        $dlq = $r->xRange(config('pipstream.stream_prefix').':stream:dlq', '-', '+');
        $this->assertCount(1, $dlq);   // the undecodable one was dead-lettered, not retried forever
    }

    public function test_risk_skip_is_explained(): void
    {
        $u = $this->customer();
        (new EventHandler)(['topic' => 'risk.limit_hit', 'trace_id' => 't', 'payload' => ['user_id' => $u->id,
            'broker_account_id' => 'b', 'copy_rule_id' => 'r', 'signal_id' => 's', 'limit' => 'kill_switch', 'detail' => '']]);
        $this->assertSame('Not copied because copying is paused.', $u->notifications()->first()->data['body']);
    }

    public function test_paystack_webhook_signature_and_idempotency(): void
    {
        $u = $this->customer();
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'pay_123', 'amount' => 250000,
            'currency' => 'KES', 'metadata' => ['user_id' => $u->id, 'plan_id' => 'pro']]]);
        $this->call('POST', '/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => 'forged'], $body)->assertUnauthorized();
        $sig = hash_hmac('sha512', $body, 'sk_test_secret');
        foreach ([1, 2] as $_) {   // delivered twice
            $this->call('POST', '/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => $sig], $body)->assertOk();
        }
        $this->assertSame(1, DB::table('invoices')->count());
        $this->assertDatabaseHas('billing_subscriptions', ['user_id' => $u->id, 'plan_id' => 'pro', 'status' => 'active']);
        $this->assertCount(1, $this->streamEvents('billing.changed'));
    }

    public function test_bot_start_links_alerts_chat(): void
    {
        $u = $this->customer();
        $url = $this->actingAs($u)->postJson('/api/v1/settings/telegram-alerts')->json('url');
        $token = substr($url, strpos($url, 'start=') + 6);
        $this->postJson('/webhooks/telegram/wrong', [])->assertNotFound();
        $this->postJson('/webhooks/telegram/test-bot-secret', ['message' => ['text' => "/start {$token}", 'chat' => ['id' => 4242, 'type' => 'private'], 'from' => ['id' => 4242, 'first_name' => 'W']]])->assertOk();
        $this->assertDatabaseHas('notification_prefs', ['user_id' => $u->id, 'channel' => 'telegram_bot', 'address' => '4242']);
        $this->getJson('/api/v1/settings')->assertJsonPath('alerts.telegram', true);
    }
}
