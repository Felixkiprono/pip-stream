<?php

namespace Tests\Feature;

use App\Models\TelegramAccount;
use App\Pipstream\TelegramLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ChannelTestTest extends TestCase
{
    use RefreshDatabase;

    private function followed(): array
    {
        $u = $this->customer();
        $acc = TelegramAccount::create(['user_id' => $u->id, 'status' => 'active', 'session_ciphertext' => 'sb1:x']);
        TelegramLink::remember(['request_id' => 'r1', 'telegram_account_id' => $acc->id, 'user_id' => $u->id,
            'status' => 'channels', 'channels' => [['tg_chat_id' => -1001, 'title' => 'Gold Room', 'username' => 'goldroom']]]);
        $this->actingAs($u)->postJson('/api/v1/channels/follow', ['channels' => [['tg_chat_id' => -1001, 'title' => 'Gold Room']]])->assertOk();

        return [$u, DB::table('channels')->value('id')];
    }

    public function test_customer_gets_a_code_and_sees_what_the_engine_made_of_the_post(): void
    {
        [$u, $ch] = $this->followed();
        $p = config('pipstream.stream_prefix');
        $r = Redis::connection('streams')->client();

        $res = $this->postJson("/api/v1/channels/{$ch}/test")->assertOk()
            ->assertJsonPath('test.state', 'waiting')->assertJsonPath('test.place', false);
        $code = $res->json('test.code');
        $id = $res->json('test.id');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', $code);
        $this->assertStringContainsString('XAUUSD BUY', $res->json('sample'));
        // what the engine looks the code up by
        $meta = json_decode($r->get("{$p}:chtest:code:{$code}"), true);
        $this->assertSame(['test_id' => $id, 'user_id' => $u->id, 'channel_id' => $ch, 'place' => false], $meta);

        // the engine fills in the result (parser, then risk)
        $state = json_decode($r->get("{$p}:chtest:{$id}"), true);
        $state = array_merge($state, ['state' => 'planned', 'done' => true,
            'arrived' => ['channel_id' => $ch, 'message_id' => 5, 'edit_version' => 0,
                'posted_at' => '2026-11-25T10:00:00+00:00', 'received_at' => '2026-11-25T10:00:01.400000+00:00'],
            'parse' => ['status' => 'parsed', 'confidence' => 1.0, 'signal' => ['kind' => 'open', 'symbol' => 'XAUUSD', 'side' => 'buy',
                'entry' => ['type' => 'range', 'from' => 2348, 'to' => 2350], 'sl' => 2340, 'tps' => [2360, 2375]]],
            'plan' => ['status' => 'would_copy', 'mode' => 'paper', 'broker_account_id' => $u->brokerAccounts()->value('id'),
                'legs' => [['symbol' => 'XAUUSD', 'side' => 'buy', 'volume' => 0.06, 'order_type' => 'market'],
                    ['symbol' => 'XAUUSD', 'side' => 'buy', 'volume' => 0.05, 'order_type' => 'market']]]]);
        $r->set("{$p}:chtest:{$id}", json_encode($state));

        $this->getJson("/api/v1/channels/{$ch}/test/{$id}")->assertOk()
            ->assertJsonPath('test.done', true)->assertJsonPath('test.arrived.seconds', 1.4)
            ->assertJsonPath('test.parse.text', 'Read as: BUY XAUUSD 2348–2350 · SL 2340 · TP 2360 / 2375')
            ->assertJsonPath('test.plan.text', 'Would copy to Practice account: 0.11 lots of XAUUSD in 2 trades, one per TP');

        // someone else can't read it
        $this->actingAs($this->customer())->getJson("/api/v1/channels/{$ch}/test/{$id}")->assertNotFound();
    }

    public function test_checks_and_latest_posts(): void
    {
        [$u, $ch] = $this->followed();
        DB::table('signals')->insert(['id' => 'sig_a', 'channel_id' => $ch, 'message_id' => 1, 'status' => 'rejected',
            'reason' => 'noise_prefilter', 'payload' => json_encode(['raw_text' => 'Good morning traders'])]);
        $res = $this->getJson("/api/v1/channels/{$ch}/check")->assertOk()
            ->assertJsonPath('checks.0.ok', true)->assertJsonPath('checks.1.ok', true)
            ->assertJsonPath('checks.2.ok', false)   // no engine heartbeat in tests
            ->assertJsonPath('recent.0.read_as', 'Not a signal: there is no buy/sell with a price in it')
            ->assertJsonPath('can_place', true);
        $this->assertSame('Trades go to Practice account', $res->json('checks.4.label'));

        $this->actingAs($this->customer())->getJson("/api/v1/channels/{$ch}/check")->assertNotFound();
    }

    public function test_telegram_test_needs_a_linked_account(): void
    {
        $this->actingAs($this->customer())->postJson('/api/v1/telegram/test')->assertStatus(409);
    }

    public function test_sample_signal_follows_the_current_gold_price(): void
    {
        $tests = app(\App\Pipstream\ChannelTest::class);
        $p = config('pipstream.stream_prefix');
        $r = Redis::connection('streams')->client();

        // nothing known yet: stop-loss and take-profit in pips, no stale levels
        $this->assertSame("XAUUSD BUY NOW\nSL: 30 pips\nTP: 60 pips", $tests->sample());

        // the latest gold signal a channel posted (an old one is ignored)
        $ch = \App\Models\Channel::create(['tg_chat_id' => -1009, 'title' => 'Gold', 'parser_profile_id' => 'pp_default']);
        DB::table('signals')->insert(['id' => 'sig_old', 'channel_id' => $ch->id, 'message_id' => 1, 'status' => 'parsed', 'kind' => 'open',
            'symbol' => 'XAUUSD', 'payload' => json_encode(['entry' => ['type' => 'market', 'price' => 3550]]), 'created_at' => now()->subDays(5)]);
        $this->assertStringContainsString('pips', $tests->sample());
        DB::table('signals')->insert(['id' => 'sig_new', 'channel_id' => $ch->id, 'message_id' => 2, 'status' => 'parsed', 'kind' => 'open',
            'symbol' => 'XAUUSD', 'payload' => json_encode(['entry' => ['type' => 'range', 'from' => 4130, 'to' => 4134]])]);
        $this->assertSame("XAUUSD BUY NOW @ 4132\nSL: 4117\nTP: 4147", $tests->sample());

        // a MetaTrader price beats both: live first, then the last one we saw
        $r->set("{$p}:quote:last:XAUUSD", '4150.4');
        $this->assertSame("XAUUSD BUY NOW @ 4150\nSL: 4135\nTP: 4165", $tests->sample());
        $r->set("{$p}:quote:XAUUSD", '4161.6');
        $this->assertSame("XAUUSD BUY NOW @ 4162\nSL: 4147\nTP: 4177", $tests->sample());
    }
}
