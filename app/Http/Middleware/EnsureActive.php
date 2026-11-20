<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A suspended customer is signed out on their next request, wherever they are logged in. */
class EnsureActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        if ($u && $u->status === 'suspended') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            abort(403, 'This account is suspended. Contact support.');
        }

        return $next($request);
    }
}
