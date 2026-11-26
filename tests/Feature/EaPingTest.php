<?php

namespace Tests\Feature;

use App\Http\Controllers\EaController;
use App\Models\BrokerAccount;
use App\Models\User;
use App\Pipstream\Bot\Bot;
use App\Pipstream\Bus;
use App\Pipstream\EaPing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "Ping MetaTrader": the EA's next check-in answers, in the app, the bot and Admin. */
class EaPingTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = 'psk_'.str_repeat('p', 40);
    }

    private function ea(User $u, string $label = 'MetaTrader 5 · Exness'): BrokerAccount
    {
        return BrokerAccount::create(['user_id' => $u->id, 'connector' => 'ea_bridge', 'label' => $label, 'enabled' => true,
            'symbol_map' => ['_ea_key_hash' => EaController::hashKey($this->key)]]);
    }

    private function sync(): void
    {
        $this->call('POST', '/ea/v1/sync', [], [], [], ['HTTP_X-PipStream-Key' => $this->key, 'CONTENT_TYPE' => 'application/json'], '{}')->assertOk();
    }

    /** MetaTrader is open: while the ping waits, the EA calls in (as it does every second). */
    private function eaIsOpen(): void
    {
        $this->app->bind(EaPing::class, fn ($app) => new class($app->make(Bus::class)) extends EaPing
        {
            protected function pause(): void
            {
                $redis = app(Bus::class)->redis()->client();
                foreach ($redis->keys(config('pipstream.stream_prefix').':eares:*:ping') ?: [] as $k) {
                    $id = explode(':', $k)[2];
                    $redis->set(config('pipstream.stream_prefix').":eares:{$id}:seen", (string) time());
                    EaPing::answer($redis, $id);
                }
            }
        });
    }

    private function redis()
    {
        return app(Bus::class)->redis()->client();
    }

    public function test_the_eas_next_call_answers_a_ping(): void
    {
        $b = $this->ea($this->customer());
        $p = config('pipstream.stream_prefix');
        $this->redis()->set("{$p}:eares:{$b->id}:ping", (string) (microtime(true) - 0.25));
        $this->sync();
        $this->assertFalse((bool) $this->redis()->exists("{$p}:eares:{$b->id}:ping"));
        $this->assertGreaterThanOrEqual(250, (int) $this->redis()->get("{$p}:eares:{$b->id}:pong"));
    }

    public function test_customer_pings_their_metatrader_and_sees_it_online(): void
    {
        $this->eaIsOpen();
        $u = $this->customer();
        $b = $this->ea($u);
        $res = $this->actingAs($u)->postJson("/api/v1/brokers/{$b->id}/ping")->assertOk();
        $this->assertTrue($res->json('ping.online'));
        $this->assertIsInt($res->json('ping.ms'));
        $this->assertStringContainsString('✅ online, answered in', $res->json('ping.text'));
        // no ping is left behind for the EA
        $this->assertFalse((bool) $this->redis()->exists(config('pipstream.stream_prefix').":eares:{$b->id}:ping"));
    }

    public function test_offline_metatrader_says_when_it_was_last_seen(): void
    {
        $u = $this->customer();
        $b = $this->ea($u);
        $this->redis()->set(config('pipstream.stream_prefix').":eares:{$b->id}:seen", (string) (time() - 1200));
        $r = app(EaPing::class)->run([$b], 0.3);
        $this->assertFalse($r[0]['online']);
        $this->assertStringContainsString('Last check-in 20 minutes ago', $r[0]['text']);
        $this->assertFalse((bool) $this->redis()->exists(config('pipstream.stream_prefix').":eares:{$b->id}:ping"));
    }

    public function test_ping_is_only_for_your_own_metatrader_and_not_too_often(): void
    {
        $this->eaIsOpen();
        $u = $this->customer();
        $b = $this->ea($u);
        $this->actingAs($this->customer())->postJson("/api/v1/brokers/{$b->id}/ping")->assertNotFound();
        $paper = BrokerAccount::where('user_id', $u->id)->where('connector', 'paper')->first();
        if ($paper) {
            $this->actingAs($u)->postJson("/api/v1/brokers/{$paper->id}/ping")->assertNotFound();
        }
        $this->actingAs($u)->postJson("/api/v1/brokers/{$b->id}/ping")->assertOk();
        $this->actingAs($u)->postJson("/api/v1/brokers/{$b->id}/ping")->assertStatus(429);
    }

    public function test_admin_pings_a_customers_metatrader(): void
    {
        $this->eaIsOpen();
        $u = $this->customer();
        $this->ea($u);
        $admin = $this->customer();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($u)->postJson("/api/v1/admin/users/{$u->id}/ping")->assertForbidden();
        $this->actingAs($admin)->postJson("/api/v1/admin/users/{$u->id}/ping")->assertOk()
            ->assertJsonPath('pings.0.online', true)->assertJsonPath('pings.0.label', 'MetaTrader 5 · Exness');
    }

    public function test_bot_ping_command_and_button(): void
    {
        $this->eaIsOpen();
        config(['pipstream.bot_token' => '123456:'.str_repeat('A', 35)]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
        $u = $this->customer();
        $this->ea($u);
        Bot::link(4343, $u, 'test');
        $sent = fn () => collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') || str_ends_with($r->url(), '/editMessageText'))->map(fn ($r) => $r->data())->values();

        $this->postJson('/webhooks/telegram/test-bot-secret', ['update_id' => 1, 'message' => ['message_id' => 5, 'text' => '/ping',
            'chat' => ['id' => 4343, 'type' => 'private'], 'from' => ['id' => 4343, 'first_name' => 'A']]])->assertOk();
        $last = $sent()->last();
        $this->assertStringContainsString('📶 Ping MetaTrader', $last['text']);
        $this->assertStringContainsString('MetaTrader 5 · Exness: ✅ online', $last['text']);

        // the Status screen offers the button
        $this->postJson('/webhooks/telegram/test-bot-secret', ['update_id' => 2, 'message' => ['message_id' => 6, 'text' => '/status',
            'chat' => ['id' => 4343, 'type' => 'private'], 'from' => ['id' => 4343, 'first_name' => 'A']]])->assertOk();
        $buttons = collect($sent()->last()['reply_markup']['inline_keyboard'] ?? [])->flatten(1)->pluck('callback_data', 'text')->all();
        $this->assertSame('m:ping', $buttons['📶 Ping MetaTrader'] ?? null);
    }
}
