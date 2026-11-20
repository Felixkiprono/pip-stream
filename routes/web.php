<?php

use App\Http\Controllers\EaController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// The EA source, with this site's address filled in (so it works on pip.stream and locally alike).
Route::get('/ea/PipStream.{ext}', [EaController::class, 'download'])->whereIn('ext', ['mq4', 'mq5']);

// Everything that isn't /api, /webhooks or /up is the React app (public/spa), like NanoDeploi.
Route::get('/{path?}', SpaController::class)->where('path', '^(?!api/|webhooks/|up$).*$');
