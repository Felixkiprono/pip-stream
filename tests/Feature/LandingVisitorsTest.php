<?php

namespace Tests\Feature;

use App\Models\User;
use App\Pipstream\Visitors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LandingVisitorsTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    public function test_visits_are_recorded_located_linked_to_signup_and_listed_for_the_superadmin(): void
    {
        Http::fake(['ipinfo.io/*' => Http::response(['ip' => '41.90.1.2', 'city' => 'Nairobi', 'region' => 'Nairobi County', 'country' => 'KE', 'org' => 'AS33771 Safaricom'])]);
        $server = ['REMOTE_ADDR' => '41.90.1.2', 'HTTP_USER_AGENT' => self::PHONE];

        $this->withServerVariables($server)->postJson('/api/v1/visit', ['path' => '/', 'referrer' => 'https://www.facebook.com/x', 'utm_source' => 'fb'])->assertNoContent();
        $this->withServerVariables($server)->postJson('/api/v1/visit', ['path' => '/'])->assertNoContent();
        // bots are not counted
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8', 'HTTP_USER_AGENT' => 'Googlebot/2.1'])->postJson('/api/v1/visit', ['path' => '/'])->assertNoContent();

        $v = DB::table('landing_visits')->orderBy('id')->get();
        $this->assertCount(2, $v);
        $this->assertSame($v[0]->visitor_id, $v[1]->visitor_id);
        $this->assertSame('mobile', $v[0]->device);
        $this->assertSame('Safari', $v[0]->browser);
        $this->assertSame('facebook.com', $v[0]->referrer_host);

        app(Visitors::class)->locate($v[0]->id);
        app(Visitors::class)->locate($v[1]->id); // reuses the first lookup
        Http::assertSentCount(1);
        $this->assertSame('Nairobi', DB::table('landing_visits')->find($v[1]->id)->city);

        // signing up links the visitor's visits to the account
        $this->withServerVariables($server)->postJson('/api/v1/auth/register', ['name' => 'Wanjiku', 'email' => 'wanjiku@example.com', 'password' => 'long-enough-1', 'accept_terms' => true])->assertOk();
        $uid = User::where('email', 'wanjiku@example.com')->value('id');
        $this->assertSame(2, DB::table('landing_visits')->where('user_id', $uid)->count());
        $this->postJson('/api/v1/auth/logout');

        $admin = User::forceCreate(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-pass-1', 'role' => 'admin']);
        $this->actingAs($admin)->getJson('/api/v1/admin/visitors')->assertForbidden();
        $sa = User::forceCreate(['name' => 'S', 'email' => 's@example.com', 'password' => 'secret-pass-1', 'role' => 'superadmin']);
        $r = $this->actingAs($sa)->getJson('/api/v1/admin/visitors?days=7')->assertOk()
            ->assertJsonPath('summary.visitors', 1)->assertJsonPath('summary.views', 2)->assertJsonPath('summary.signed_up', 1)
            ->assertJsonPath('rows.0.ip', '41.90.1.2')->assertJsonPath('rows.0.city', 'Nairobi')->assertJsonPath('rows.0.country', 'KE')
            ->assertJsonPath('rows.0.email', 'wanjiku@example.com')->assertJsonPath('rows.0.views', 2)->assertJsonPath('rows.0.referrer', 'facebook.com')
            ->assertJsonPath('countries.0.key', 'KE');
        $this->getJson('/api/v1/admin/visitors?q=nairobi')->assertJsonCount(1, 'rows');
        $this->getJson('/api/v1/admin/visitors?country=US')->assertJsonCount(0, 'rows');
    }
}
