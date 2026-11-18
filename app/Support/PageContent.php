<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Editable rich-text pages. Each page keeps one table per language
 * (about / abouteng / aboutru ...) and the newest row is the live version.
 */
class PageContent
{
    public const PAGES = [
        'about' => ['table' => 'about', 'column' => 'about', 'route' => 'haqimiz', 'title' => 'About the journal'],
        'aim' => ['table' => 'aim', 'column' => 'aim', 'route' => 'maqsadimiz', 'title' => 'Aims and scope'],
        'team' => ['table' => 'team', 'column' => 'team', 'route' => 'jamoa', 'title' => 'Editorial board'],
        'instruction' => ['table' => 'instruction', 'column' => 'instruction', 'route' => 'yoriqnoma', 'title' => 'Author guidelines'],
        'plagiarism' => ['table' => 'plagarism', 'column' => 'plagarism', 'route' => 'kochirma', 'title' => 'Plagiarism policy'],
        'submission' => ['table' => 'submission', 'column' => 'submission', 'route' => 'submit', 'title' => 'Submission rules'],
    ];

    public static function exists(string $page): bool
    {
        return isset(self::PAGES[$page]);
    }

    public static function table(string $page, string $locale): string
    {
        $suffix = ['uz' => '', 'en' => 'eng', 'ru' => 'ru'][Locale::normalize($locale)];

        return self::PAGES[$page]['table'].$suffix;
    }

    public static function get(string $page, ?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();
        $column = self::PAGES[$page]['column'];
        $html = DB::table(self::table($page, $locale))->orderByDesc('id')->value($column);

        // Fall back to Uzbek so a missing translation never renders an empty page.
        if (blank(strip_tags((string) $html)) && $locale !== 'uz') {
            return self::get($page, 'uz');
        }

        return $html;
    }

    public static function raw(string $page, string $locale): ?string
    {
        return DB::table(self::table($page, $locale))->orderByDesc('id')->value(self::PAGES[$page]['column']);
    }

    public static function put(string $page, string $locale, string $html): void
    {
        $table = self::table($page, $locale);
        $column = self::PAGES[$page]['column'];
        $id = DB::table($table)->orderByDesc('id')->value('id');

        if ($id) {
            DB::table($table)->where('id', $id)->update([$column => $html, 'updated_at' => now()]);
        } else {
            DB::table($table)->insert([$column => $html, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
