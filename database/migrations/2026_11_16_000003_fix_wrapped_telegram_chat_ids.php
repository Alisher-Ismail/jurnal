<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * On a 32-bit PHP, Telegram ids above 2^31 (e.g. 7000000001) were saved wrapped to 32 bits
 * (-1589934591), so the bot answered a chat that does not exist ("chat not found").
 * The bot now keeps ids as strings; this removes the wrapped copies of the editors' chats,
 * which are recreated with the right id on their next message.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('telegram_chats')) {
            return;
        }

        // Editors' chats and the storage channel (a -100… channel id also wraps).
        $ids = array_merge(config('services.telegram.admin_chat_ids', []), [config('services.telegram.storage_chat_id')]);
        try {
            $saved = \App\Support\RuntimeSettings::telegram();
            $ids = array_merge($ids, $saved['admin_chat_ids'] ?? [], [$saved['storage_chat_id'] ?? null]);
        } catch (Throwable $e) {
            // settings table not there yet: the .env editors are enough
        }

        foreach (array_unique(array_map('strval', array_filter($ids))) as $id) {
            if (! preg_match('/^-?\d+$/', $id)) {
                continue;
            }
            $wrapped = self::wrap32($id);
            if ($wrapped !== $id) {
                DB::table('telegram_chats')->where('chat_id', $wrapped)->delete();
            }
        }
    }

    public function down(): void
    {
        // nothing to restore: the deleted rows held a wrong id
    }

    /** The value a 32-bit integer conversion gives for $id, as a string (floats are exact below 2^53). */
    private static function wrap32(string $id): string
    {
        $n = fmod((float) $id + 2147483648.0, 4294967296.0);
        if ($n < 0) {
            $n += 4294967296.0;
        }

        return sprintf('%.0f', $n - 2147483648.0);
    }
};
