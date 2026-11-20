<?php

use App\Http\Controllers\EaController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// .mq4/.mq5: the source with this site's address; .ex4/.ex5: ready-built, 404 when we don't have one (never the app's HTML).
Route::get('/ea/PipStream.{ext}', [EaController::class, 'download'])->whereIn('ext', ['mq4', 'mq5', 'ex4', 'ex5']);
// One-click setup scripts (the one-line commands on the Brokers page)
Route::get('/ea/install/{token}.{ext}', [EaController::class, 'installScript'])->where('token', 'pst_[A-Za-z0-9]{40}')->whereIn('ext', ['sh', 'ps1'])->middleware('throttle:30,1');

// Everything that isn't /api, /webhooks or /up is the React app (public/spa), like NanoDeploi.
Route::get('/{path?}', SpaController::class)->where('path', '^(?!api/|webhooks/|up$).*$');
