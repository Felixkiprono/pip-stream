<?php

namespace Tests\Feature;

use App\Models\BrokerAccount;
use App\Models\Channel;
use App\Models\User;
use App\Pipstream\Accounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminInsightsTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        $u = User::forceCreate(['name' => 'Felix', 'email' => 'sa@example.com', 'password' => 'secret-pass-1', 'role' => 'superadmin']);
        app(Accounts::class)->onboard($u, quiet: true);

        return $u;
    }

    public function test_insights_brokers_and_activity_are_superadmin_only(): void
    {
        $admin = User::forceCreate(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-pass-1', 'role' => 'admin']);
        foreach (['insights', 'brokers', 'activity'] as $p) {
            $this->actingAs($admin)->getJson("/api/v1/admin/{$p}")->assertForbidden();
            $this->actingAs($this->customer())->getJson("/api/v1/admin/{$p}")->assertForbidden();
        }
    }

    public function test_birds_eye_view_of_customers_brokers_and_copying(): void
    {
        $sa = $this->superadmin();
        $u = $this->customer(['email' => 'trader@example.com']);
        $this->customer(['email' => 'idle@example.com']);
        $ch = Channel::create(['tg_chat_id' => -100991, 'title' => 'Gold Room', 'parser_profile_id' => 'pp_default']);
        DB::table('subscriptions')->insert(['user_id' => $u->id, 'channel_id' => $ch->id]);
        DB::table('telegram_accounts')->insert(['id' => 'tga_1', 'user_id' => $u->id, 'status' => 'active', 'session_ciphertext' => 'sealed']);
        $b = $this->actingAs($u)->postJson('/api/v1/brokers', ['connector' => 'ea_bridge', 'platform' => 'mt5'])->json('broker.id');
        DB::table('signals')->insert(['id' => 'sig1', 'channel_id' => $ch->id, 'message_id' => 1, 'status' => 'parsed', 'kind' => 'open', 'payload' => '{}']);
        DB::table('orders')->insert([
            ['id' => 'o1', 'intent_id' => 'i1', 'user_id' => $u->id, 'broker_account_id' => $b, 'action' => 'open', 'status' => 'executed', 'symbol' => 'XAUUSD', 'error' => null, 'latency_ms' => 300],
            ['id' => 'o2', 'intent_id' => 'i2', 'user_id' => $u->id, 'broker_account_id' => $b, 'action' => 'open', 'status' => 'failed', 'symbol' => 'XAUUSD', 'error' => 'market closed', 'latency_ms' => 900],
        ]);
        DB::table('positions')->insert(['id' => 'p1', 'user_id' => $u->id, 'broker_account_id' => $b, 'channel_id' => $ch->id,
            'source_message_id' => 1, 'signal_id' => 'sig1', 'ticket' => '77', 'symbol' => 'XAUUSD', 'side' => 'buy', 'volume' => 0.01]);
        DB::table('invoices')->insert(['id' => 'inv1', 'user_id' => $u->id, 'amount_cents' => 250000, 'currency' => 'KES', 'provider' => 'paystack', 'status' => 'paid']);

        // requests update last seen
        $this->assertNotNull($u->fresh()->last_seen_at);

        $r = $this->actingAs($sa)->getJson('/api/v1/admin/insights?days=7')->assertOk();
        $funnel = collect($r->json('funnel'))->pluck('n', 'key');
        $this->assertSame(2, $funnel['signed_up']); // customers only, not the superadmin
        $this->assertSame(1, $funnel['telegram']);
        $this->assertSame(1, $funnel['following']);
        $this->assertSame(1, $funnel['broker']);
        $this->assertSame(1, $funnel['copied']);
        $r->assertJsonPath('window.fills', 1)->assertJsonPath('window.failed', 1)
            ->assertJsonPath('failures.0.reason', 'market closed')
            ->assertJsonPath('top_customers.0.email', 'trader@example.com')
            ->assertJsonPath('revenue.all_time.0.cents', 250000)
            ->assertJsonPath('people.seen_1d', 1)
            ->assertJsonPath('top_channels.0.followers', 1);
        $this->assertCount(7, $r->json('series'));

        $acc = collect($this->getJson('/api/v1/admin/brokers')->assertOk()->json('accounts'))->keyBy('id');
        $this->assertSame('trader@example.com', $acc[$b]['owner']['email']);
        $this->assertSame(1, $acc[$b]['fills_30d']);
        $this->assertSame(1, $acc[$b]['failed_30d']);
        $this->assertArrayNotHasKey('credentials_ciphertext', $acc[$b]);
        $this->assertCount(1, $this->getJson('/api/v1/admin/brokers?connector=ea_bridge')->json('accounts'));

        $this->getJson('/api/v1/admin/activity?status=failed')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.error', 'market closed');
        $this->getJson('/api/v1/admin/activity?q=trader')->assertJsonCount(2, 'rows');
        $this->getJson('/api/v1/admin/activity?view=positions&status=open')->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.channel', 'Gold Room');

        $row = collect($this->getJson('/api/v1/admin/users')->json('users'))->firstWhere('email', 'trader@example.com');
        $this->assertSame(1, $row['brokers']);
        $this->assertNotNull($row['last_seen']);
        $this->assertNotNull(BrokerAccount::find($b));
    }

    public function test_superadmin_command_reports_and_seeds(): void
    {
        config(['pipstream.superadmin_email' => 'boss@example.com', 'pipstream.superadmin_password' => '']);
        $this->artisan('pipstream:superadmin')->expectsOutputToContain('No account')->assertFailed();
        config(['pipstream.superadmin_password' => 'very-secret-1']);
        $this->artisan('pipstream:superadmin --seed')->expectsOutputToContain('Superadmin is in place')->assertSuccessful();
        $this->assertTrue(User::where('email', 'boss@example.com')->first()->isSuperadmin());
    }
}
