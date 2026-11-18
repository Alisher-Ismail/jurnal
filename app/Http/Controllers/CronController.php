<?php

namespace App\Http\Controllers;

use App\Services\Originality\CorpusHarvester;
use App\Services\SpaceSaver;
use App\Support\AppSetting;
use App\Support\RuntimeSettings;

/**
 * Entry point for cPanel "Cron Jobs" on hosting without a terminal:
 *   wget -q -O /dev/null "https://journal.emro.uz/public/cron/<secret>"
 * The secret is CRON_SECRET from .env, or the one Panel → System generates.
 */
class CronController extends Controller
{
    public const LAST_RUN = 'cron_last_run';

    public const LAST_CLEANUP = 'cron_last_cleanup';

    /** How often the panel's "Clear cache" and leftover clean-up run by themselves. */
    public const CLEANUP_HOURS = 6;

    public function __invoke(string $secret, CorpusHarvester $harvester, SpaceSaver $saver)
    {
        $expected = (string) RuntimeSettings::cronSecret();
        abort_unless($expected !== '' && hash_equals($expected, $secret), 404);

        ignore_user_abort(true);
        AppSetting::put(self::LAST_RUN, now()->toIso8601String());

        // A few files per run go to Telegram, so the server copies disappear without anyone pressing buttons.
        try {
            $space = $saver->run(15);
        } catch (\Throwable $e) {
            $space = ['error' => $e->getMessage()];
        }

        $harvest = $harvester->harvest(40);

        // Older articles get their Zenodo DOI a few at a time.
        try {
            $zenodo = app(\App\Services\ZenodoBacklog::class)->run(40, 8);
        } catch (\Throwable $e) {
            $zenodo = ['error' => $e->getMessage()];
        }

        // Citations and Zenodo views/downloads shown on the article pages, each article about once a week.
        try {
            $metrics = app(\App\Services\ArticleMetrics::class)->refresh(20, 20);
        } catch (\Throwable $e) {
            $metrics = ['error' => $e->getMessage()];
        }

        // New papers announced in the Telegram channel (when switched on in Panel → System).
        try {
            $channel = app(\App\Services\ChannelPoster::class)->run(2);
        } catch (\Throwable $e) {
            $channel = ['error' => $e->getMessage()];
        }

        return response()->json($harvest + ['space' => $space, 'zenodo' => $zenodo, 'metrics' => $metrics, 'channel' => $channel, 'cleanup' => $this->cleanup($saver)]);
    }

    /**
     * Every CLEANUP_HOURS: what the "Clear cache" and "Files to Telegram" buttons do beyond the
     * regular file moving above — stamped PDF copies, Laravel caches, leftover update zips and old logs.
     */
    private function cleanup(SpaceSaver $saver): ?array
    {
        $last = AppSetting::get(self::LAST_CLEANUP);
        if ($last && \Illuminate\Support\Carbon::parse($last)->gt(now()->subHours(self::CLEANUP_HOURS))) {
            return null;
        }
        // Noted first (in the database, which the cache clearing does not touch): one run even if two crons overlap.
        AppSetting::put(self::LAST_CLEANUP, now()->toIso8601String());
        try {
            $leftovers = $saver->removeLeftovers();
            $stamped = is_dir(storage_path('app/stamped')) ? $saver->dirSize(storage_path('app/stamped')) : 0;
            \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/stamped')); // regenerated on the next download
            \Illuminate\Support\Facades\Artisan::call('optimize:clear');

            return ['freed_mb' => round(($leftovers['bytes'] + $stamped) / 1048576, 1), 'removed' => array_keys($leftovers['removed'])];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
