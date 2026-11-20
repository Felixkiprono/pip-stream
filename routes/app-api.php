<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BrokerController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\DetailController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TelegramController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- auth
Route::get('auth/me', [AuthController::class, 'me']);
Route::middleware('throttle:10,1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
});
Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('auth');

Route::middleware(['auth', 'active'])->group(function () {
    // ------------------------------------------------------------ home
    Route::get('home', [HomeController::class, 'home']);
    Route::get('pulse', [HomeController::class, 'pulse']);
    Route::get('stats', [HomeController::class, 'stats']);
    Route::get('trades', [HomeController::class, 'trades']);
    Route::get('alerts', [HomeController::class, 'alerts']);
    Route::post('alerts/read', [HomeController::class, 'markRead']);
    Route::get('alerts/{id}', [DetailController::class, 'alert']);
    Route::get('trades/{position}', [DetailController::class, 'position']);
    Route::get('signals/{signal}', [DetailController::class, 'signal']);
    Route::post('pause', [HomeController::class, 'pause']);

    // ------------------------------------------------------------ Telegram linking
    Route::get('telegram', [TelegramController::class, 'show']);
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('telegram/phone', [TelegramController::class, 'phone']);
        Route::post('telegram/code', [TelegramController::class, 'code']);
        Route::post('telegram/resend', [TelegramController::class, 'resend']);
        Route::post('telegram/qr', [TelegramController::class, 'qr']);
        Route::post('telegram/password', [TelegramController::class, 'password']);
    });
    Route::get('telegram/requests/{rid}', [TelegramController::class, 'request']);
    Route::get('telegram/channels', [TelegramController::class, 'channels']);
    Route::post('telegram/disconnect', [TelegramController::class, 'disconnect']);

    // ------------------------------------------------------------ channels + copy style
    Route::get('channels', [ChannelController::class, 'index']);
    Route::post('channels/follow', [ChannelController::class, 'follow']);
    Route::delete('channels/{channel}', [ChannelController::class, 'unfollow']);
    Route::get('styles', [ChannelController::class, 'styles']);
    Route::put('channels/{channel}/rule', [ChannelController::class, 'rule']);

    // ------------------------------------------------------------ brokers
    Route::get('brokers', [BrokerController::class, 'index']);
    Route::post('brokers', [BrokerController::class, 'store']);
    Route::delete('brokers/{broker}', [BrokerController::class, 'destroy']);

    // ------------------------------------------------------------ billing + settings
    Route::get('billing', [BillingController::class, 'show']);
    Route::post('billing/checkout', [BillingController::class, 'checkout']);
    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings', [SettingsController::class, 'update']);
    Route::post('settings/telegram-alerts', [SettingsController::class, 'telegramAlerts']);

    // ------------------------------------------------------------ admin
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('overview', [AdminController::class, 'overview']);
        Route::get('dashboard', [AdminController::class, 'dashboard']);
        Route::get('system', [AdminController::class, 'system']);
        Route::post('system/telegram-test', [AdminController::class, 'telegramTest'])->middleware('throttle:6,1');
        Route::post('kill-switch', [AdminController::class, 'killSwitch']);
        Route::get('channels', [AdminController::class, 'channels']);
        Route::put('channels/{channel}', [AdminController::class, 'updateChannel']);
        Route::post('parse-preview', [AdminController::class, 'parsePreview']);
        Route::get('review', [AdminController::class, 'review']);
        Route::get('users', [AdminController::class, 'users']);
        Route::get('users/{user}', [AdminController::class, 'user']);
        Route::post('users/{user}/status', [AdminController::class, 'userStatus']);
        Route::post('users/{user}/pause', [AdminController::class, 'userPause']);
        Route::post('users/{user}/role', [AdminController::class, 'userRole']);
        Route::post('users/{user}/trial', [AdminController::class, 'userTrial']);
        Route::get('audit', [AdminController::class, 'audit']);
    });
});
