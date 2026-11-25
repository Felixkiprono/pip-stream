<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended customer is signed out on their next request, wherever they are logged in.
 * Also notes when each person was last seen (at most every 5 minutes) for the admin's active-user numbers.
 */
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
        if ($u && (! $u->last_seen_at || $u->last_seen_at->lt(now()->subMinutes(5)))) {
            DB::table('users')->where('id', $u->id)->update(['last_seen_at' => now()]); // no updated_at bump
            $u->last_seen_at = now();
        }

        return $next($request);
    }
}
