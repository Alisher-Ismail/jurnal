<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

/** Whether the database has been updated for the current code (uploads can arrive before "Run database update"). */
class SchemaState
{
    private static ?bool $originality = null;
    private static ?array $pending = null;
    private static ?bool $corpus = null;

    /** Tables of the originality / authorship check exist. */
    public static function originalityReady(): bool
    {
        if (self::$originality === null) {
            try {
                self::$originality = Schema::hasTable('paper_checks') && Schema::hasTable('plagiarism_documents')
                    && Schema::hasTable('plagiarism_fingerprints') && Schema::hasColumn('paper_checks', 'conflict');
            } catch (Throwable $e) {
                self::$originality = false;
            }
        }

        return self::$originality;
    }

    /** Tables of the harvested Uzbek publications corpus exist. */
    public static function corpusReady(): bool
    {
        try {
            return self::$corpus ??= self::originalityReady() && Schema::hasTable('external_works') && Schema::hasTable('app_settings');
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return string[] migrations not run yet */
    public static function pendingMigrations(): array
    {
        if (self::$pending === null) {
            try {
                $migrator = app('migrator');
                self::$pending = $migrator->repositoryExists()
                    ? array_values(array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan()))
                    : ['(migrations table)'];
            } catch (Throwable $e) {
                self::$pending = [];
            }
        }

        return self::$pending;
    }

    public static function reset(): void
    {
        self::$originality = null;
        self::$pending = null;
        self::$corpus = null;
    }
}
