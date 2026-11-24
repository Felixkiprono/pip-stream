<?php

namespace App\Providers;

use App\Pipstream\MailSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // Resend key and sender from Admin → Email (encrypted in platform_settings), .env as fallback
        try {
            MailSettings::apply();
        } catch (\Throwable) {
            // no cache or database yet (image build, artisan optimize): .env mail settings stay
        }
    }
}
