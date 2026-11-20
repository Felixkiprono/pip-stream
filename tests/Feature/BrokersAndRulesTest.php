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
}
