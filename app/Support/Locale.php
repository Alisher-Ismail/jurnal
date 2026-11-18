<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

class Locale
{
    /**
     * Public URLs keep their historical shape: Uzbek has no suffix,
     * English routes end in "eng" and Russian ones in "ru" (/maqola/5, /maqolaeng/5).
     */
    public const SUFFIXES = ['uz' => '', 'en' => 'eng', 'ru' => 'ru'];

    public const LABELS = ['uz' => 'Oʻzbekcha', 'en' => 'English', 'ru' => 'Русский'];

    public static function all(): array
    {
        return array_keys(self::SUFFIXES);
    }

    public static function normalize(?string $locale): string
    {
        return array_key_exists((string) $locale, self::SUFFIXES) ? $locale : 'uz';
    }

    public static function suffix(?string $locale = null): string
    {
        return self::SUFFIXES[self::normalize($locale ?? app()->getLocale())];
    }

    /** Localized URL for one of the public routes registered by name without suffix. */
    public static function route(string $name, $parameters = [], ?string $locale = null, bool $absolute = true): string
    {
        return route($name.self::suffix($locale), $parameters, $absolute);
    }

    /** The current page in another language. */
    public static function switchUrl(string $locale): string
    {
        $route = request()->route();
        $name = $route ? (string) $route->getName() : '';
        $current = self::suffix();
        $base = $current === '' ? $name : (str_ends_with($name, $current) ? substr($name, 0, -strlen($current)) : '');

        if ($base !== '' && Route::has($base) && Route::has($base.self::suffix($locale))) {
            // Only parameters that change the content (pagination, search) are carried over,
            // so language links and hreflang tags never pick up stray query strings.
            $query = array_filter(request()->only(['page', 'search']), fn ($v) => $v !== null && $v !== '' && $v !== '1');

            return route($base.self::suffix($locale), $route->parameters()).($query ? '?'.http_build_query($query) : '');
        }

        return request()->fullUrlWithQuery(['lang' => $locale]);
    }
}
