<?php

namespace Tests\Feature;

use App\Models\BrokerAccount;
use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BrokersAndRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_broker_password_is_sealed_and_never_returned(): void
    {
        $u = $this->customer();
        DB::table('billing_subscriptions')->where('user_id', $u->id)->update(['plan_id' => 'pro']);
        $res = $this->actingAs($u)->postJson('/api/v1/brokers', ['connector' => 'mt5_bridge', 'login' => '5012345',
            'password' => 'Hunter2-Secret!', 'server' => 'Exness-MT5Trial']);
        $res->assertOk()->assertJsonPath('broker.connected', true)->assertJsonPath('broker.login_hint', '•••345');
        $this->assertStringNotContainsString('Hunter2', $res->getContent());

        $b = BrokerAccount::find($res->json('broker.id'));
        $this->assertStringStartsWith('sb1:', $b->credentials_ciphertext);
        $this->assertStringNotContainsString('Hunter2', $b->credentials_ciphertext);
        // only the holder of the executor's PRIVATE key can open it
        $plain = sodium_crypto_box_seal_open(base64_decode(substr($b->credentials_ciphertext, 4)), $this->executorSecret);
        $this->assertSame(['login' => '5012345', 'password' => 'Hunter2-Secret!', 'server' => 'Exness-MT5Trial'], json_decode($plain, true));
        $this->assertSame('broker_account', $this->streamEvents('config.changed')[0]['payload']['entity']);
    }

    public function test_metatrader_via_metaapi_is_sealed_and_shows_connection_status(): void
    {
        $u = $this->customer();
        $this->actingAs($u)->postJson('/api/v1/brokers', ['connector' => 'metaapi', 'platform' => 'mt5',
            'login' => '1', 'password' => 'x', 'server' => 's'])->assertStatus(402);  // hosted is a paid-plan feature
        DB::table('billing_subscriptions')->where('user_id', $u->id)->update(['plan_id' => 'pro', 'status' => 'active']);
        $u->refresh();
        $res = $this->actingAs($u)->postJson('/api/v1/brokers', ['connector' => 'metaapi', 'platform' => 'mt4',
            'login' => ' 51234567 ', 'password' => 'Hunter2-Secret!', 'server' => 'Exness-MT4Trial7']);
        $res->assertOk()->assertJsonPath('broker.label', 'MetaTrader 4 · Exness-MT4Trial7')
            ->assertJsonPath('broker.platform', 'mt4')->assertJsonPath('broker.connected', false)
            ->assertJsonPath('broker.status.state', 'connecting');
        $this->assertStringNotContainsString('Hunter2', $res->getContent());

        $b = BrokerAccount::find($res->json('broker.id'));
        $plain = sodium_crypto_box_seal_open(base64_decode(substr($b->credentials_ciphertext, 4)), $this->executorSecret);
        $this->assertSame(['login' => '51234567', 'password' => 'Hunter2-Secret!', 'server' => 'Exness-MT4Trial7',
            'platform' => 'mt4'], json_decode($plain, true));

        // the engine reports progress in redis; the app shows it
        app(\App\Pipstream\Bus::class)->redis()->client()->set(config('pipstream.stream_prefix').":acct:{$b->id}:status",
            json_encode(['state' => 'connected', 'detail' => 'MT4 account connected']));
        $this->getJson('/api/v1/brokers')->assertJsonPath('brokers.1.connected', true)
            ->assertJsonPath('brokers.1.status.state', 'connected');
        $this->getJson("/api/v1/brokers/{$b->id}")->assertJsonPath('broker.status.detail', 'MT4 account connected');

        $this->postJson('/api/v1/brokers', ['connector' => 'metaapi', 'login' => '1', 'password' => 'x', 'server' => 's'])
            ->assertStatus(422)->assertJsonValidationErrors('platform');
    }

    public function test_plan_limits_broker_count(): void
    {
        $u = $this->customer();   // trial: 1 real broker
        $this->actingAs($u)->postJson('/api/v1/brokers', ['connector' => 'deriv_options', 'token' => 'a1-abcdefgh'])->assertOk();
        $this->postJson('/api/v1/brokers', ['connector' => 'deriv_options', 'token' => 'a1-zzzzzzzz'])->assertStatus(402);
    }

    public function test_copy_style_and_live_needs_pro(): void
    {
        $u = $this->customer();
        $ch = Channel::create(['tg_chat_id' => -100777, 'title' => 'Gold Room', 'parser_profile_id' => 'pp_default']);
        DB::table('subscriptions')->insert(['user_id' => $u->id, 'channel_id' => $ch->id]);
        $this->actingAs($u)->putJson("/api/v1/channels/{$ch->id}/rule", ['style' => 'careful'])
            ->assertOk()->assertJsonPath('rule.style', 'careful')->assertJsonPath('rule.mode', 'paper');
        $this->assertDatabaseHas('copy_rules', ['user_id' => $u->id, 'channel_id' => $ch->id, 'risk_pct' => 0.5]);

        $broker = $this->postJson('/api/v1/brokers', ['connector' => 'deriv_options', 'token' => 'a1-abcdefgh'])->json('broker.id');
        $this->putJson("/api/v1/channels/{$ch->id}/rule", ['style' => 'balanced', 'broker_account_id' => $broker, 'live' => true])
            ->assertStatus(402);
        $this->putJson("/api/v1/channels/{$ch->id}/rule", ['style' => 'balanced', 'broker_account_id' => $broker])
            ->assertOk()->assertJsonPath('rule.mode', 'shadow');   // real broker, not live yet: watch-only
        DB::table('billing_subscriptions')->where('user_id', $u->id)->update(['plan_id' => 'pro', 'status' => 'active']);
        $this->putJson("/api/v1/channels/{$ch->id}/rule", ['style' => 'balanced', 'broker_account_id' => $broker, 'live' => true])
            ->assertOk()->assertJsonPath('rule.mode', 'live');
    }

    public function test_cannot_set_rules_on_unfollowed_channel(): void
    {
        $ch = Channel::create(['tg_chat_id' => -100778, 'title' => 'Other', 'parser_profile_id' => 'pp_default']);
        $this->actingAs($this->customer())->putJson("/api/v1/channels/{$ch->id}/rule", ['style' => 'bold'])->assertNotFound();
    }

    public function test_any_plan_can_copy_into_a_demo_metatrader_account(): void
    {
        $u = $this->customer();
        $ch = Channel::create(['tg_chat_id' => -100779, 'title' => 'Gold Desk', 'parser_profile_id' => 'pp_default']);
        DB::table('subscriptions')->insert(['user_id' => $u->id, 'channel_id' => $ch->id]);
        $b = $this->actingAs($u)->postJson('/api/v1/brokers', ['connector' => 'ea_bridge', 'platform' => 'mt4'])->json('broker.id');
        $redis = app(\App\Pipstream\Bus::class)->redis()->client();
        $key = config('pipstream.stream_prefix').":eares:{$b}:state";

        $redis->set($key, json_encode(['demo' => false]));
        $this->putJson("/api/v1/channels/{$ch->id}/rule", ['style' => 'fixed', 'fixed_lots' => 0.01, 'broker_account_id' => $b, 'live' => true])
            ->assertStatus(402);

        $redis->set($key, json_encode(['demo' => true]));
        $this->putJson("/api/v1/channels/{$ch->id}/rule", ['style' => 'fixed', 'fixed_lots' => 0.01, 'broker_account_id' => $b, 'live' => true])
            ->assertOk()->assertJsonPath('rule.mode', 'live');
        // changing only the style keeps it live
        $this->putJson("/api/v1/channels/{$ch->id}/rule", ['style' => 'careful', 'broker_account_id' => $b])
            ->assertOk()->assertJsonPath('rule.mode', 'live');
        $redis->del($key);
    }

    public function test_trades_show_account_fill_time_speed_and_refusals(): void
    {
        $u = $this->customer();
        $b = $this->actingAs($u)->postJson('/api/v1/brokers', ['connector' => 'ea_bridge', 'platform' => 'mt4'])->json('broker.id');
        $practice = BrokerAccount::where('user_id', $u->id)->where('connector', 'paper')->value('id');
        foreach ([[$b, '409849834'], [$practice, '1']] as $i => [$acct, $ticket]) {
            DB::table('positions')->insert(['id' => "pos{$i}", 'user_id' => $u->id, 'broker_account_id' => $acct, 'channel_id' => 'c',
                'source_message_id' => 1, 'signal_id' => 's', 'ticket' => $ticket, 'symbol' => 'XAUUSDm', 'side' => 'buy', 'volume' => 0.01]);
            DB::table('orders')->insert(['id' => "o{$i}", 'intent_id' => "i{$i}", 'user_id' => $u->id, 'broker_account_id' => $acct,
                'action' => 'open', 'status' => 'executed', 'ticket' => $ticket, 'latency_ms' => 640]);
        }
        DB::table('orders')->insert(['id' => 'of', 'intent_id' => 'if', 'user_id' => $u->id, 'broker_account_id' => $b,
            'action' => 'open', 'status' => 'failed', 'symbol' => 'XAUUSDm', 'error' => 'market closed', 'latency_ms' => 900]);

        $t = collect($this->getJson('/api/v1/trades')->assertOk()->json('trades'))->keyBy('ticket');
        $this->assertFalse($t['409849834']['account']['practice']);
        $this->assertSame(640, $t['409849834']['latency_ms']);
        $this->assertNotNull($t['409849834']['filled_at']);
        $this->assertTrue($t['1']['account']['practice']);
        $this->getJson('/api/v1/trades')->assertJsonPath('failed.0.error', 'market closed');
    }

    public function test_practice_trades_can_be_closed_by_hand_but_real_ones_cannot(): void
    {
        $u = $this->customer();
        $b = $this->actingAs($u)->postJson('/api/v1/brokers', ['connector' => 'ea_bridge', 'platform' => 'mt4'])->json('broker.id');
        $practice = BrokerAccount::where('user_id', $u->id)->where('connector', 'paper')->value('id');
        foreach ([['p1', $practice, '7', 0.04], ['p2', $practice, '8', 0.06], ['r1', $b, '409849834', 0.01]] as [$id, $acct, $ticket, $vol]) {
            DB::table('positions')->insert(['id' => $id, 'user_id' => $u->id, 'broker_account_id' => $acct, 'channel_id' => 'c',
                'source_message_id' => 1, 'signal_id' => 's', 'ticket' => $ticket, 'symbol' => 'XAUUSD', 'side' => 'buy',
                'volume' => $vol, 'open_price' => 3550, 'status' => 'open']);
        }
        $redis = app(\App\Pipstream\Bus::class)->redis()->client();
        $p = config('pipstream.stream_prefix');
        $redis->hset("{$p}:paper:{$practice}:positions", '7', '{}');
        $redis->set("{$p}:quote:XAUUSD", '3552');

        $this->postJson('/api/v1/trades/p1/close')->assertOk()->assertJsonPath('trade.profit', 8);   // 2 x 0.04 lot x 100 oz
        $this->assertFalse((bool) $redis->hexists("{$p}:paper:{$practice}:positions", '7'));
        $this->postJson('/api/v1/trades/p1/close')->assertStatus(422);
        $this->postJson('/api/v1/trades/r1/close')->assertStatus(422);

        $this->postJson('/api/v1/trades/close-practice')->assertOk()->assertJsonCount(1, 'closed');
        $this->assertSame('closed', DB::table('positions')->where('id', 'p2')->value('status'));
        $this->assertSame('open', DB::table('positions')->where('id', 'r1')->value('status'));
        $redis->del("{$p}:quote:XAUUSD", "{$p}:paper:{$practice}:positions");
    }
}
