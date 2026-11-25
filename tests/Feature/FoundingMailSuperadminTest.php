<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\Connected;
use App\Notifications\ResetPassword;
use App\Notifications\TestEmail;
use App\Notifications\Welcome;
use App\Pipstream\Accounts;
use App\Pipstream\EventHandler;
use App\Pipstream\MailSettings;
use Database\Seeders\SuperadminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FoundingMailSuperadminTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $email)
    {
        return $this->postJson('/api/v1/auth/register', ['name' => 'Akinyi Otieno', 'email' => $email, 'password' => 'long-enough-1', 'accept_terms' => true]);
    }

    public function test_first_customers_are_founding_members_for_life(): void
    {
        Notification::fake();
        $admin = User::forceCreate(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => 'secret-pass-1', 'role' => 'admin']);
        config(['pipstream.founding_slots' => 2]);
        app(Accounts::class)->onboard($admin, quiet: true); // admins never take a founding spot
        $this->assertNull($admin->fresh()->founding_number);

        $one = $this->register('one@example.com')->assertOk()->assertJsonPath('user.founding', 1)
            ->assertJsonPath('user.plan.lifetime', true)->assertJsonPath('user.plan.founding', 1)
            ->assertJsonPath('user.plan.tier', 'premium')->assertJsonPath('user.plan.limits.live', true);
        $this->getJson('/api/v1/billing')->assertJsonPath('current.plan', 'lifetime')->assertJsonPath('current.until', null);
        // Pro checkout is refused: nothing may replace free-for-life
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'pro'])->assertStatus(422);
        $this->postJson('/api/v1/auth/logout');

        $this->register('two@example.com')->assertJsonPath('user.founding', 2);
        $this->postJson('/api/v1/auth/logout');
        $this->register('three@example.com')->assertJsonPath('user.founding', null)
            ->assertJsonPath('user.plan.lifetime', false)->assertJsonPath('user.plan.status', 'trialing');

        $first = User::where('email', 'one@example.com')->first();
        Notification::assertSentTo($first, Welcome::class, fn ($n) => $n->founding === 1);
        Notification::assertSentTo(User::where('email', 'three@example.com')->first(), Welcome::class, fn ($n) => $n->founding === null);

        // hosted MetaTrader (a Pro feature) is allowed for a founding member
        $this->actingAs($first);
        $res = $this->postJson('/api/v1/brokers', ['connector' => 'metaapi', 'label' => 'Hosted', 'login' => '123', 'password' => 'x', 'server' => 'Demo']);
        $this->assertNotSame(402, $res->status());

        // drift is repaired: if the plan were ever changed, /auth/me puts Lifetime back
        $first->billing->forceFill(['plan_id' => 'trial', 'status' => 'trialing', 'current_period_end' => now()->addDay()])->save();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('user.plan.lifetime', true);
    }

    public function test_forgot_and_reset_password(): void
    {
        Notification::fake();
        $u = $this->customer(['email' => 'reset@example.com']);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'RESET@example.com'])->assertOk();
        $token = null;
        Notification::assertSentTo($u, ResetPassword::class, function ($n) use (&$token, $u) {
            $token = $n->token;

            return str_contains($n->toMail($u)->actionUrl, '/reset-password?token=');
        });
        $this->postJson('/api/v1/auth/reset-password', ['token' => 'bad', 'email' => 'reset@example.com', 'password' => 'brand-new-pass'])->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'reset@example.com', 'password' => 'brand-new-pass'])
            ->assertOk()->assertJsonPath('user.id', $u->id);
        $this->assertTrue(Hash::check('brand-new-pass', $u->fresh()->password));
    }

    public function test_superadmin_seeder_reads_password_from_env(): void
    {
        config(['pipstream.superadmin_password' => '']);
        (new SuperadminSeeder)->run();
        $this->assertNull(User::where('email', 'felixkiprono@yahoo.com')->first());

        config(['pipstream.superadmin_password' => 'from-the-env-1']);
        (new SuperadminSeeder)->run();
        (new SuperadminSeeder)->run(); // rerun is safe
        $u = User::where('email', 'felixkiprono@yahoo.com')->firstOrFail();
        $this->assertSame('superadmin', $u->role);
        $this->assertTrue($u->isAdmin());
        $this->assertTrue(Hash::check('from-the-env-1', $u->password));
        $this->assertSame('lifetime', $u->billing->plan_id);
        $this->assertNull($u->founding_number);
        $this->postJson('/api/v1/auth/login', ['email' => 'felixkiprono@yahoo.com', 'password' => 'from-the-env-1'])
            ->assertOk()->assertJsonPath('user.role', 'superadmin');
        $this->getJson('/api/v1/admin/overview')->assertOk();
    }

    public function test_mail_settings_are_superadmin_only_encrypted_and_write_only(): void
    {
        $admin = $this->customer();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin)->getJson('/api/v1/admin/mail')->assertForbidden();

        $super = $this->customer(['email' => 'super@example.com']);
        $super->forceFill(['role' => 'superadmin'])->save();
        $this->actingAs($super)->getJson('/api/v1/admin/mail')->assertOk()->assertJsonPath('source', 'none');
        $this->putJson('/api/v1/admin/mail', ['resend_key' => 'not-a-key', 'from_address' => 'a@pips.stream', 'from_name' => 'PipStream'])->assertStatus(422);
        $this->putJson('/api/v1/admin/mail', ['resend_key' => 're_secret_ABCD', 'from_address' => 'hello@pips.stream', 'from_name' => 'PipStream'])
            ->assertOk()->assertJsonPath('source', 'admin')->assertJsonPath('key_hint', '••••ABCD')
            ->assertJsonPath('from_address', 'hello@pips.stream')->assertJsonPath('mailer', 'resend')->assertJsonMissing(['re_secret_ABCD']);
        // encrypted at rest
        $raw = \DB::table('platform_settings')->where('key', 'mail')->value('value');
        $this->assertStringNotContainsString('re_secret_ABCD', $raw);
        $this->assertSame('re_secret_ABCD', PlatformSetting::read('mail')['resend_key']);
        $this->assertSame('re_secret_ABCD', config('services.resend.key'));
        // saving without a key keeps the stored one
        $this->putJson('/api/v1/admin/mail', ['from_address' => 'hello@pips.stream', 'from_name' => 'PipStream Alerts'])
            ->assertJsonPath('key_hint', '••••ABCD')->assertJsonPath('from_name', 'PipStream Alerts');
        $this->assertSame('PipStream Alerts', config('mail.from.name'));

        Notification::fake();
        $this->postJson('/api/v1/admin/mail/test')->assertOk()->assertJsonPath('to', 'super@example.com');
        Notification::assertSentTo($super, TestEmail::class);

        $this->putJson('/api/v1/admin/mail', ['clear_key' => true, 'from_address' => 'hello@pips.stream', 'from_name' => 'PipStream'])
            ->assertJsonPath('source', 'none');
        $this->assertSame('none', MailSettings::source());
    }

    public function test_telegram_linked_event_sends_one_email(): void
    {
        Notification::fake();
        $u = $this->customer();
        $handler = app(EventHandler::class);
        $ev = ['topic' => 'tg.login.status', 'payload' => ['request_id' => 'r1', 'telegram_account_id' => 'tga_1',
            'user_id' => $u->id, 'status' => 'linked', 'phone_hint' => '789']];
        $handler($ev);
        $handler($ev);
        Notification::assertSentToTimes($u, Connected::class, 1);
    }
}
