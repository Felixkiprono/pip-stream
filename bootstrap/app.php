<?php

use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureSuperadmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // JSON API for the React app: same-origin, session cookie, CSRF protected
            Route::middleware('web')->prefix('api/v1')->group(base_path('routes/app-api.php'));
            // Signed webhooks from payment and Telegram: no session, no CSRF
            Route::prefix('webhooks')->group(base_path('routes/webhooks.php'));
            // MetaTrader Expert Advisor check-ins: per-account key, no session, no CSRF
            Route::prefix('ea/v1')->group(base_path('routes/ea.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Traefik (NanoDeploi) -> nginx -> php-fpm: trust the proxy chain for scheme and host
        $middleware->trustProxies(at: '*');
        $middleware->preventRequestForgery(except: ['webhooks/*', 'ea/*']);
        $middleware->alias(['admin' => EnsureAdmin::class, 'superadmin' => EnsureSuperadmin::class, 'active' => EnsureActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('webhooks/*') || $request->expectsJson(),
        );
    })->create();
