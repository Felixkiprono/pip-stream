<?php

namespace Tests\Feature;

use App\Models\BrokerAccount;
use App\Pipstream\Bus;
use App\Pipstream\Watchdog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WatchdogTest extends TestCase
{
    use RefreshDatabase;

    private function healthy(): void
    {
        $r = app(Bus::class)->redis()->client();
        $p = config('pipstream.stream_prefix');
        foreach (['intake', 'risk', 'executor'] as $w) {
            $r->set("{$p}:health:{$w}", (string) microtime(true));
        }
        Cache::put('pipstream:consumer:beat', microtime(true), 60);
        Cache::put('pipstream:queue:beat', microtime(true), 600);
    }

    private function warm(): void
    {
        Cache::put('watchdog:started', microtime(true) - 3600, now()->addDay());
    }

    public function test_everything_up_opens_nothing(): void
    {
        Notification::fake();
        $this->healthy();
        $this->warm();
        $this->artisan('pipstream:watchdog')->assertSuccessful();
        $this->assertSame(0, DB::table('system_alerts')->count());
        Notification::assertNothingSent();
    }

    public function test_engine_down_alerts_once_then_recovers(): void
    {
        Notification::fake();
        config(['pipstream.alert_emails' => 'ops@example.com']);
        $this->healthy();
        $this->warm();
        app(Bus::class)->redis()->client()->set(config('pipstream.stream_prefix').':health:risk', (string) (microtime(true) - 600));

        app(Watchdog::class)->run();
        app(Watchdog::class)->run();  // still down: no second email
        $this->assertSame(1, DB::table('system_alerts')->whereNull('resolved_at')->where('key', 'engine:risk')->count());
        Notification::assertSentTimes(\App\Notifications\SystemAlert::class, 1);
        Notification::assertSentTo(new AnonymousNotifiable, \App\Notifications\SystemAlert::class,
            fn ($n, $ch, $to) => $to->routes['mail'] === 'ops@example.com' && ! $n->recovered);

        $this->healthy();
        app(Watchdog::class)->run();
        $this->assertSame(0, DB::table('system_alerts')->whereNull('resolved_at')->count());
        Notification::assertSentTo(new AnonymousNotifiable, \App\Notifications\SystemAlert::class, fn ($n) => $n->recovered);
    }

    public function test_no_alerts_while_warming_up_after_a_deploy(): void
    {
        Notification::fake();
        Cache::forget('pipstream:queue:beat');  // nothing has run yet
        app(Watchdog::class)->run();
        $this->assertSame(0, DB::table('system_alerts')->where('key', 'like', 'worker:%')->count());
    }

    public function test_queue_worker_hourly_restart_is_not_an_outage(): void
    {
        Notification::fake();
        $this->healthy();
        $this->warm();
        Cache::put('pipstream:queue:beat', microtime(true) - 90, 600);  // worker restarting, last job 90 s ago
        app(Watchdog::class)->run();
        $this->assertSame(0, DB::table('system_alerts')->count());
    }

    public function test_metatrader_offline_tells_the_customer_and_admin(): void
    {
        Notification::fake();
        $this->healthy();
        $this->warm();
        $u = $this->customer();
        $b = BrokerAccount::create(['user_id' => $u->id, 'connector' => 'ea_bridge', 'label' => 'MetaTrader 5 · Exness', 'enabled' => true]);
        $ch = DB::table('channels')->insertGetId(['id' => 'ch_t', 'tg_chat_id' => -100123, 'title' => 'T'], 'id');
        DB::table('copy_rules')->insert(['id' => 'rule_t', 'user_id' => $u->id, 'channel_id' => 'ch_t', 'broker_account_id' => $b->id,
            'mode' => 'live', 'sizing_mode' => 'fixed', 'fixed_lots' => 0.01]);
        $r = app(Bus::class)->redis()->client();
        $seen = config('pipstream.stream_prefix').":eares:{$b->id}:seen";

        $r->set($seen, (string) time());
        app(Watchdog::class)->run();
        $this->assertSame(0, DB::table('system_alerts')->count());

        $r->set($seen, (string) (time() - 600));
        app(Watchdog::class)->run();
        $this->assertSame(1, DB::table('system_alerts')->whereNull('resolved_at')->where('key', "ea:{$b->id}")->where('user_id', $u->id)->count());
        Notification::assertSentTo($u, \App\Notifications\TradeAlert::class, fn ($n) => $n->kind === 'ea_offline');
        Notification::assertSentTo(new AnonymousNotifiable, \App\Notifications\SystemAlert::class);

        $r->set($seen, (string) time());
        app(Watchdog::class)->run();
        Notification::assertSentTo($u, \App\Notifications\TradeAlert::class, fn ($n) => $n->kind === 'ea_online');
    }

    public function test_order_failure_spike_alerts(): void
    {
        Notification::fake();
        $this->healthy();
        $this->warm();
        $u = $this->customer();
        $b = BrokerAccount::where('user_id', $u->id)->first();
        foreach (range(1, 6) as $i) {
            DB::table('orders')->insert(['id' => "ord_{$i}", 'intent_id' => "int_{$i}", 'user_id' => $u->id, 'broker_account_id' => $b->id,
                'action' => 'open', 'status' => 'failed', 'symbol' => 'EURUSD', 'error' => 'MetaTrader terminal offline']);
        }
        app(Watchdog::class)->run();
        $this->assertSame(1, DB::table('system_alerts')->where('key', 'errors:orders')->count());
    }

    public function test_admin_system_page_lists_incidents(): void
    {
        $admin = $this->customer();
        $admin->forceFill(['role' => 'admin'])->save();
        DB::table('system_alerts')->insert(['key' => 'engine:risk', 'title' => 'Engine risk worker down', 'detail' => 'x', 'started_at' => now()]);
        $this->actingAs($admin)->getJson('/api/v1/admin/system')->assertOk()
            ->assertJsonPath('alerts.0.key', 'engine:risk')
            ->assertJsonFragment(['name' => 'Open incidents', 'state' => 'fail']);
    }
}
