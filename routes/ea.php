<?php

use App\Http\Controllers\EaController;
use Illuminate\Support\Facades\Route;

// The PipStream Expert Advisor in a customer's MT4/MT5 terminal: key auth, no session.
Route::post('sync', [EaController::class, 'sync'])->middleware('throttle:240,1');
