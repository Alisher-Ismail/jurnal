<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Lets pages on zenodo.org read public article metadata and PDFs, so articles can be deposited
 * to Zenodo from the editor's logged-in browser session (no API token stored here).
 */
class AllowZenodo
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if ($request->headers->get('Origin') === 'https://zenodo.org') {
            $response->headers->set('Access-Control-Allow-Origin', 'https://zenodo.org');
            $response->headers->set('Vary', 'Origin');
        }

        return $response;
    }
}
