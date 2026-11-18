<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settings edited in the admin panel (Panel → System) and stored in the database.
 * They override the matching .env values, so the hosting's .env file does not have to be edited.
 */
class RuntimeSettings
{
    public const TELEGRAM = 'telegram';

    /** Apply saved settings to the runtime configuration (one small query per request). */
    public static function apply(): void
    {
        try {
            $telegram = self::telegram();
        } catch (Throwable $e) {
            return; // table not created yet (before "Run database update")
        }

        foreach (['token', 'username', 'storage_chat_id', 'webhook_secret'] as $key) {
            if (filled($telegram[$key] ?? null)) {
                config(['services.telegram.'.$key => $telegram[$key]]);
            }
        }
        if (! empty($telegram['admin_chat_ids'])) {
            config(['services.telegram.admin_chat_ids' => array_values(array_map('strval', $telegram['admin_chat_ids']))]);
        }
    }

    public const CRON = 'cron_secret';

    /** Key in the cron URL: CRON_SECRET from .env, else the one saved in the database (null if none yet). */
    public static function cronSecret(bool $create = false): ?string
    {
        $secret = config('journal.cron_secret') ?: AppSetting::get(self::CRON);
        if (! $secret && $create) {
            $secret = \Illuminate\Support\Str::random(32);
            AppSetting::put(self::CRON, $secret);
        }

        return $secret ?: null;
    }

    /** @return array{token: ?string, username: ?string, storage_chat_id: ?string, admin_chat_ids: string[], webhook_secret: ?string} */
    public static function telegram(): array
    {
        $raw = DB::table('app_settings')->where('key', self::TELEGRAM)->value('value');
        $data = $raw ? (json_decode($raw, true) ?: []) : [];
        if (! empty($data['token'])) {
            try {
                $data['token'] = Crypt::decryptString($data['token']);
            } catch (Throwable $e) {
                $data['token'] = null; // APP_KEY changed: the token must be entered again
            }
        }

        return $data + ['token' => null, 'username' => null, 'storage_chat_id' => null, 'admin_chat_ids' => [], 'webhook_secret' => null];
    }

    /** A group got a new id (upgraded to a supergroup): use it wherever the old one was configured. */
    public static function replaceChat(string $old, string $new): bool
    {
        $saved = self::telegram();
        $editors = $saved['admin_chat_ids'] ?: config('services.telegram.admin_chat_ids', []);
        $storage = (string) ($saved['storage_chat_id'] ?: config('services.telegram.storage_chat_id'));
        if (! in_array($old, array_map('strval', $editors), true) && $storage !== $old) {
            return false;
        }

        self::saveTelegram([
            'admin_chat_ids' => array_map(fn ($id) => (string) $id === $old ? $new : $id, $editors),
            'storage_chat_id' => $storage === $old ? $new : ($saved['storage_chat_id'] ?: null),
        ]);

        return true;
    }

    public static function saveTelegram(array $values): void
    {
        $data = array_merge(self::telegram(), $values);
        $data['admin_chat_ids'] = array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), $data['admin_chat_ids'] ?? []))));
        if (! empty($data['token'])) {
            $data['token'] = Crypt::encryptString($data['token']);
        }
        AppSetting::put(self::TELEGRAM, $data);
        self::apply();
    }
}
