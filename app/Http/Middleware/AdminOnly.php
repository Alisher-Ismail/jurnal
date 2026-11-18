<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Administrator-only parts of the panel; editors (reviewers) get "403". */
class AdminOnly
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->isAdmin(), 403, __('Only the administrator can do this.'));

        return $next($request);
    }
}
