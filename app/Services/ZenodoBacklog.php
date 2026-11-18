<?php

namespace App\Services;

use App\Models\Article;
use App\Support\AppSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gives older articles (published before Zenodo was connected) a DOI, a few at a time:
 * from Panel → System or from the cron job. Zenodo allows roughly 100 API requests a minute;
 * one deposit uses about four, and a "too many requests" answer ends the round early.
 * Articles that fail are remembered with the reason and skipped until retried from the panel.
 */
class ZenodoBacklog
{
    public const FAILED = 'zenodo_backlog_failed';

    private const SLOTS = 3;

    public function __construct(private ZenodoService $zenodo)
    {
    }

    public function ready(): bool
    {
        return $this->zenodo->enabled();
    }

    private function withoutDoi(): Builder
    {
        return Article::query()
            ->where(fn ($q) => $q->whereNull('doi')->orWhere('doi', ''))
            ->where(fn ($q) => $q->whereNotNull('pdf_file_id')->orWhere(fn ($q) => $q->whereNotNull('pdf')->where('pdf', '!=', '')));
    }

    /** @return array<int, string> article id => reason */
    public function failed(): array
    {
        return (array) (AppSetting::get(self::FAILED, []) ?: []);
    }

    public function stats(): array
    {
        $reasons = $this->failed();
        $failed = array_keys($reasons);

        return [
            'reasons' => array_slice($reasons, -5, 5, true),
            'total' => Article::count(),
            'with_doi' => Article::whereNotNull('doi')->where('doi', '!=', '')->count(),
            'remaining' => $this->withoutDoi()->whereNotIn('id', $failed ?: [0])->count(),
            'failed' => count($failed),
        ];
    }

    public function retryFailed(): void
    {
        AppSetting::put(self::FAILED, []);
    }

    /** @return array{done: int, failed: int, stopped: ?string} */
    public function run(float $seconds = 50, int $max = 15): array
    {
        $result = ['done' => 0, 'failed' => 0, 'stopped' => null];
        if (! $this->ready()) {
            $result['stopped'] = 'Zenodo is not connected';

            return $result;
        }
        // At most SLOTS rounds at once (panel, cron, several browser tabs); each article is locked on its own,
        // so two rounds never deposit the same article twice.
        $slot = null;
        for ($i = 1; $i <= self::SLOTS && ! $slot; $i++) {
            $lock = Cache::lock('zenodo-backlog-slot-'.$i, (int) $seconds + 300);
            $slot = $lock->get() ? $lock : null;
        }
        if (! $slot) {
            $result['stopped'] = 'Another round is already running';

            return $result;
        }
        try {
            return $this->round($seconds, $max, $result);
        } finally {
            $slot->release();
        }
    }

    private function round(float $seconds, int $max, array $result): array
    {
        @set_time_limit((int) $seconds + 120);
        $start = microtime(true);
        $newFailures = [];

        $candidates = $this->withoutDoi()->whereNotIn('id', array_keys($this->failed()) ?: [0])
            ->orderBy('id')->limit($max * (self::SLOTS + 1))->pluck('id');
        foreach ($candidates as $id) {
            if ($result['done'] + $result['failed'] >= $max || microtime(true) - $start > $seconds) {
                break;
            }
            $lock = Cache::lock('zenodo-article-'.$id, 900);
            if (! $lock->get()) {
                continue; // another round is depositing it right now
            }
            try {
                $article = Article::find($id);
                if (! $article || filled($article->doi)) {
                    continue;
                }
                $this->zenodo->deposit($article);
                $result['done']++;
            } catch (Throwable $e) {
                $message = mb_substr($e->getMessage(), 0, 300);
                if (str_contains($message, ' 429') || stripos($message, 'too many') !== false) {
                    $result['stopped'] = 'Zenodo rate limit — continues on the next run';
                    break;
                }
                $newFailures[$id] = $message;
                $result['failed']++;
                Log::warning('Zenodo backlog deposit failed', ['article' => $id, 'error' => $message]);
            } finally {
                $lock->release();
            }
            usleep(300000);
        }
        if ($newFailures) {
            AppSetting::put(self::FAILED, $newFailures + $this->failed()); // re-read: other rounds may have added some
        }

        return $result;
    }
}
