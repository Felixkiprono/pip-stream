<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_gives_practice_account_and_trial(): void
    {
        $res = $this->postJson('/api/v1/auth/register', ['name' => 'Otieno', 'email' => 'otieno@example.com', 'password' => 'long-enough-1']);
        $res->assertOk()->assertJsonPath('user.role', 'customer')
            ->assertJsonPath('user.onboarding.steps.telegram', false);
        $this->assertStringStartsWith('usr_', $res->json('user.id'));
        $this->getJson('/api/v1/brokers')->assertJsonPath('brokers.0.connector', 'paper')
            ->assertJsonPath('brokers.0.connected', true);
        $this->getJson('/api/v1/billing')->assertJsonPath('current.status', 'trialing')->assertJsonPath('current.entitled', true);
    }

    public function test_login_logout_and_bad_password(): void
    {
        $u = $this->customer(['email' => 'amina@example.com']);
        $this->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'secret-pass-1'])
            ->assertOk()->assertJsonPath('user.id', $u->id);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->getJson('/api/v1/home')->assertUnauthorized();
    }

    public function test_customers_cannot_reach_admin_api(): void
    {
        $this->actingAs($this->customer());
        $this->getJson('/api/v1/admin/overview')->assertForbidden();
        $this->postJson('/api/v1/admin/kill-switch', ['on' => true])->assertForbidden();
    }

    public function test_admin_overview_and_global_pause(): void
    {
        $admin = $this->customer(['role' => 'admin']);
        $this->actingAs($admin)->getJson('/api/v1/admin/overview')->assertOk()->assertJsonPath('global_pause', false)
            ->assertJsonCount(3, 'workers');
        $this->postJson('/api/v1/admin/kill-switch', ['on' => true])->assertOk();
        $this->getJson('/api/v1/admin/overview')->assertJsonPath('global_pause', true);
        $this->assertSame('kill_switch', $this->streamEvents('config.changed')[0]['payload']['entity']);
        $this->assertDatabaseHas('audit_log', ['action' => 'kill_switch_on', 'actor' => $admin->id]);
    }

    public function test_customer_pause_is_per_user(): void
    {
        $u = $this->customer();
        $this->actingAs($u)->postJson('/api/v1/pause', ['on' => true])->assertJsonPath('paused', true);
        $this->getJson('/api/v1/home')->assertJsonPath('paused', true)->assertJsonPath('global_pause', false);
    }
}
