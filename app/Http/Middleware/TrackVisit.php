<?php

namespace App\Http\Middleware;

use App\Services\VisitTracker;
use Closure;
use Illuminate\Http\Request;

/** Counts the request for Panel → Visitors after the response has been sent (never slows a page). */
class TrackVisit
{
    public function handle(Request $request, Closure $next, string $kind = 'page')
    {
        $response = $next($request);
        app()->terminating(fn () => app(VisitTracker::class)->record($request, $response, $kind));

        return $response;
    }
}
