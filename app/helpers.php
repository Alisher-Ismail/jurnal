<?php

use App\Support\Locale;

if (! function_exists('lroute')) {
    /** URL of a public page in the current (or given) language. */
    function lroute(string $name, $parameters = [], ?string $locale = null): string
    {
        return Locale::route($name, $parameters, $locale);
    }
}
