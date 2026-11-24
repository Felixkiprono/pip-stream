<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Platform settings (email keys) are for the superadmin only, not every admin. */
class EnsureSuperadmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->isSuperadmin(), 403, 'Superadmin only');

        return $next($request);
    }
}
