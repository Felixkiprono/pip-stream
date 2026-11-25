<?php

namespace App\Providers;

use App\Pipstream\MailSettings;
use App\Pipstream\PublicUrl;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Named limiters: plain throttle:N,1 shares one counter per IP across routes, so an EA syncing
        // every second from a customer's PC would use up that customer's login and setup budget.
        $per = fn (string $name, int $n) => RateLimiter::for($name, fn (Request $r) => Limit::perMinute($n)->by($name.'|'.$r->ip()));
        $per('ea-sync', 240);
        $per('ea-pair', 60);
        $per('ea-install', 30);
        $per('auth', 10);
        $per('writes', 20);
        $per('installer', 10);
        $per('admin-test', 6);
        $per('visits', 30);

        // Resend key and sender from Admin → Email (encrypted in platform_settings), .env as fallback
        try {
            MailSettings::apply();
        } catch (\Throwable) {
            // no cache or database yet (image build, artisan optimize): .env mail settings stay
        }
        // A long-running queue worker booted before the last save in Admin → Email: re-read the
        // key and sender before every job (one cached read), so queued mail never uses a stale key.
        Queue::before(function () {
            try {
                MailSettings::apply();
            } catch (\Throwable) {
            }
        });

        // Links in emails: the worker and scheduler have no request, so url() would fall back to
        // APP_URL as typed. Pin them to the public https address. Web requests keep their own host.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            URL::forceRootUrl(PublicUrl::base());
        }
    }
}
