<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\Connected;
use App\Notifications\PasswordChanged;
use App\Notifications\ResetPassword;
use App\Notifications\Welcome;
use App\Pipstream\MailSettings;
use App\Pipstream\PublicUrl;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Customer portal sign-in over email: sign-up, sign-in, forgot / reset, password changed. */
class AccountEmailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_sends_welcome_right_away_and_signs_in(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/register', ['name' => 'Akinyi Otieno', 'email' => 'Akinyi@Example.com', 'password' => 'long-enough-1', 'accept_terms' => true])
            ->assertOk()->assertJsonPath('user.email', 'akinyi@example.com');
        $u = User::where('email', 'akinyi@example.com')->firstOrFail();
        Notification::assertSentTo($u, Welcome::class, fn ($n, $channels) => $channels === ['mail']
            && str_contains($n->toMail($u)->actionUrl, '/start/telegram'));
        $this->assertNotInstanceOf(ShouldQueue::class, new Welcome); // never waits on the queue worker
        $this->getJson('/api/v1/auth/me')->assertJsonPath('user.email', 'akinyi@example.com');

        // the same address again is refused, any casing
        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/register', ['name' => 'X', 'email' => 'akinyi@example.com', 'password' => 'long-enough-1', 'accept_terms' => true])->assertStatus(422);
    }

    public function test_login_any_email_casing_and_wrong_password_and_suspended(): void
    {
        $u = $this->customer(['email' => 'login@example.com']);
        $this->postJson('/api/v1/auth/login', ['email' => 'LOGIN@example.com', 'password' => 'nope-nope'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/auth/login', ['email' => 'LOGIN@example.com', 'password' => 'secret-pass-1'])
            ->assertOk()->assertJsonPath('user.id', $u->id);
        $this->postJson('/api/v1/auth/logout')->assertOk();

        $u->forceFill(['status' => 'suspended'])->save();
        $this->postJson('/api/v1/auth/login', ['email' => 'login@example.com', 'password' => 'secret-pass-1'])->assertStatus(422);
    }

    public function test_full_reset_flow_links_to_https_and_confirms_by_email(): void
    {
        Notification::fake();
        $u = $this->customer(['email' => 'reset@example.com']);
        $u->forceFill(['email_verified_at' => null])->save();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'Reset@Example.com'])
            ->assertOk()->assertJsonPath('ok', true);
        $token = null;
        Notification::assertSentTo($u, ResetPassword::class, function ($n, $channels) use (&$token, $u) {
            $token = $n->token;
            $url = $n->toMail($u)->actionUrl;

            return $channels === ['mail'] && str_starts_with($url, PublicUrl::base().'/reset-password?token=')
                && str_contains($url, 'email=reset%40example.com');
        });
        $this->assertNotInstanceOf(ShouldQueue::class, new ResetPassword('x'));

        // a second request inside a minute is throttled with a clear message
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        // too-short password, then the right one
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'reset@example.com', 'password' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'RESET@example.com', 'password' => 'brand-new-pass'])
            ->assertOk()->assertJsonPath('user.id', $u->id);
        $u->refresh();
        $this->assertTrue(Hash::check('brand-new-pass', $u->password));
        $this->assertNotNull($u->email_verified_at);
        Notification::assertSentTo($u, PasswordChanged::class, fn ($n) => $n->how === 'reset'
            && str_contains($n->toMail($u)->actionUrl, '/forgot-password'));

        // the link works once
        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'reset@example.com', 'password' => 'another-pass-1'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        // and the new password signs in
        $this->postJson('/api/v1/auth/login', ['email' => 'reset@example.com', 'password' => 'brand-new-pass'])->assertOk();
    }

    public function test_forgot_password_does_not_reveal_accounts_or_mail_suspended_users(): void
    {
        Notification::fake();
        $s = $this->customer(['email' => 'gone@example.com']);
        $s->forceFill(['status' => 'suspended'])->save();
        $a = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json('message');
        $b = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'gone@example.com'])->assertOk()->json('message');
        $this->assertSame($a, $b);
        Notification::assertNotSentTo($s, ResetPassword::class);
    }

    public function test_change_password_in_settings(): void
    {
        Notification::fake();
        $u = $this->customer(['email' => 'settings@example.com']);
        $this->actingAs($u);
        $this->putJson('/api/v1/settings/password', ['current_password' => 'wrong-one', 'password' => 'newer-pass-1'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/settings/password', ['current_password' => 'secret-pass-1', 'password' => 'secret-pass-1'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/settings/password', ['current_password' => 'secret-pass-1', 'password' => 'newer-pass-1'])->assertOk();
        $this->assertTrue(Hash::check('newer-pass-1', $u->fresh()->password));
        Notification::assertSentTo($u, PasswordChanged::class, fn ($n) => $n->how === 'settings');
    }

    public function test_public_url_is_https_pips_stream_in_production(): void
    {
        $this->app['env'] = 'production';
        try {
            foreach (['http://localhost', '', 'http://127.0.0.1:8000', 'http://php'] as $bad) {
                config(['app.url' => $bad, 'pipstream.public_url' => null]);
                $this->assertSame('https://pips.stream', PublicUrl::base(), "APP_URL={$bad}");
            }
            config(['app.url' => 'http://pips.stream/']);
            $this->assertSame('https://pips.stream', PublicUrl::base());
            config(['pipstream.public_url' => 'https://app.pips.stream']);
            $this->assertSame('https://app.pips.stream/reset-password?token=t', PublicUrl::to('/reset-password', ['token' => 't']));
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_queued_mail_uses_the_key_saved_in_admin_after_the_worker_started(): void
    {
        $u = $this->customer();
        MailSettings::apply();
        $this->assertNotSame('resend', config('mail.default'));
        // saved from Admin → Email by the web process while this "worker" was running
        PlatformSetting::write(MailSettings::KEY, ['resend_key' => 're_new_key_WXYZ', 'from_address' => 'hello@pips.stream', 'from_name' => 'PipStream']);
        $this->assertNotSame('re_new_key_WXYZ', config('services.resend.key'));
        event(new JobProcessing('redis', $this->createStub(Job::class)));
        $this->assertSame('re_new_key_WXYZ', config('services.resend.key'));
        $this->assertSame('resend', config('mail.default'));
        $this->assertSame('hello@pips.stream', config('mail.from.address'));
        // the follow-up mails that do go through the queue link to the public address
        $this->assertStringStartsWith(PublicUrl::base().'/start/channels', (new Connected('telegram'))->toMail($u)->actionUrl);
    }
}
