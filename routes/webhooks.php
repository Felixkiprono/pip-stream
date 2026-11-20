<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('paystack', [WebhookController::class, 'paystack']);
Route::post('telegram/{secret}', [WebhookController::class, 'telegramBot']);
