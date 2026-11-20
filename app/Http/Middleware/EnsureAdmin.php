<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Server-side guard for /api/v1/admin: hiding screens in React is not access control. */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Admins only');

        return $next($request);
    }
}
