<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finishes an upload without anyone pressing buttons: the first request after new files
 * arrive runs the database update and clears compiled caches, once.
 *
 * The fingerprint is the list of migration files plus the code version, so it changes
 * exactly when an update brings something that needs these steps.
 */
class AutoUpdate
{
    public const VERSION = '2026.11.15.26';

    public static function run(): void
    {
        if (! config('journal.auto_update', true) || app()->runningInConsole()) {
            return;
        }

        $marker = storage_path('framework/deployed-version');
        $fingerprint = self::fingerprint();
        if (@file_get_contents($marker) === $fingerprint) {
            return;
        }

        // Only one request does the work; the others carry on as usual.
        $lock = @fopen(storage_path('framework/deploy.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return;
        }

        try {
            @set_time_limit(300);
            if (SchemaState::pendingMigrations()) {
                Artisan::call('migrate', ['--force' => true]);
                SchemaState::reset();
                Log::info('Auto update: database updated', ['output' => trim(Artisan::output())]);
            }
            Artisan::call('view:clear');
            // packages.php/services.php are rebuilt from the installed package list on the next request.
            foreach (['config.php', 'routes-v7.php', 'routes.php', 'packages.php', 'services.php'] as $cached) {
                @unlink(base_path('bootstrap/cache/'.$cached));
            }
            file_put_contents($marker, $fingerprint);
        } catch (Throwable $e) {
            // Leave the marker unwritten so the next request (or Panel → System) tries again.
            Log::error('Auto update failed', ['error' => $e->getMessage()]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function fingerprint(): string
    {
        $migrations = array_map('basename', glob(database_path('migrations/*.php')) ?: []);
        sort($migrations);

        return self::VERSION.':'.md5(implode(',', $migrations));
    }
}
