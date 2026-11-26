<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The Alerts page: newest first, 20 a page. */
class AlertsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_alerts_come_a_page_at_a_time(): void
    {
        $u = $this->customer();
        DB::table('notifications')->where('notifiable_id', $u->id)->delete();
        foreach (range(1, 45) as $i) {
            DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\TradeAlert',
                'notifiable_type' => $u->getMorphClass(), 'notifiable_id' => $u->id,
                'data' => json_encode(['kind' => 'ea_online', 'title' => "Alert {$i}", 'body' => 'x']),
                'read_at' => $i < 45 ? now() : null, 'created_at' => now()->subMinutes(100 - $i), 'updated_at' => now()]);
        }
        $this->actingAs($u);

        $p1 = $this->getJson('/api/v1/alerts')->assertOk()
            ->assertJsonPath('page', 1)->assertJsonPath('pages', 3)->assertJsonPath('total', 45)->assertJsonPath('unread', 1);
        $this->assertCount(20, $p1->json('alerts'));
        $this->assertSame('Alert 45', $p1->json('alerts.0.title'));  // newest first

        $p3 = $this->getJson('/api/v1/alerts?page=3')->assertOk()->assertJsonPath('page', 3);
        $this->assertCount(5, $p3->json('alerts'));
        $this->assertSame('Alert 1', $p3->json('alerts.4.title'));

        $this->getJson('/api/v1/alerts?page=99')->assertJsonPath('page', 3);  // past the end: the last page
        $this->getJson('/api/v1/alerts?page=abc')->assertJsonPath('page', 1);
    }
}
