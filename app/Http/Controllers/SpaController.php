<?php

namespace App\Http\Controllers;

/** Serves the built React app (web/ -> public/spa). Laravel only adds the session cookie. */
class SpaController extends Controller
{
    public function __invoke()
    {
        $index = public_path('spa/index.html');
        abort_unless(is_file($index), 503, 'The web app is not built yet: run `npm ci && npm run build`.');

        return response()->file($index, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']);
    }
}
