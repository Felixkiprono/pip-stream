<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// Everything that isn't /api, /webhooks or /up is the React app (public/spa), like NanoDeploi.
Route::get('/{path?}', SpaController::class)->where('path', '^(?!api/|webhooks/|up$).*$');
