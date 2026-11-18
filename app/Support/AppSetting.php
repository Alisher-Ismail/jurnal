<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Persistent key/value settings (unlike the cache, survive "Clear cache"). */
class AppSetting
{
    public static function get(string $key, $default = null)
    {
        $value = DB::table('app_settings')->where('key', $key)->value('value');

        return $value === null ? $default : json_decode($value, true);
    }

    public static function put(string $key, $value): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]);
    }
}
