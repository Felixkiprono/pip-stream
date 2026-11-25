<?php

namespace App\Http\Controllers;

use App\Pipstream\Visitors;
use Illuminate\Http\Request;

/** The landing page reports a page view here (no cookies beyond the session, no third-party scripts). */
class VisitController extends Controller
{
    public function store(Request $r, Visitors $visitors)
    {
        $d = $r->validate([
            'path' => ['nullable', 'string', 'max:300'], 'referrer' => ['nullable', 'string', 'max:1000'],
            'utm_source' => ['nullable', 'string', 'max:100'], 'utm_medium' => ['nullable', 'string', 'max:100'], 'utm_campaign' => ['nullable', 'string', 'max:100'],
        ]);
        if ($id = $visitors->record($r, $d)) {
            defer(fn () => $visitors->locate($id));
        }

        return response()->noContent();
    }
}
