<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;

/**
 * Localized routes pass their language as a parameter ("locale:en").
 * Other pages use ?lang=, then the visitor's last choice, then Uzbek.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next, ?string $locale = null)
    {
        $locale = $locale ?? $request->query('lang') ?? $request->session()->get('locale');
        $locale = Locale::normalize($locale);

        app()->setLocale($locale);
        $request->session()->put('locale', $locale);

        return $next($request);
    }
}
