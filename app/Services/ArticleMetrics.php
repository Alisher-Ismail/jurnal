<?php

namespace App\Services;

use App\Models\Article;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Keeps each article's outside numbers fresh, a few articles per cron run (each about once a week):
 * views and downloads of its Zenodo record, and citations of its DOI as counted by DataCite (which
 * registers the Zenodo DOIs) and OpenAlex (which follows references across scholarly papers).
 * Google Scholar has no public API, so the article page links to it instead.
 */
class ArticleMetrics
{
    private const REFRESH_DAYS = 7;

    /** @return array{checked: int, errors: int} */
    public function refresh(float $seconds = 20, int $max = 20): array
    {
        $result = ['checked' => 0, 'errors' => 0];
        if (! Schema::hasColumn('article', 'metrics_checked_at')) {
            return $result;
        }
        $start = microtime(true);
        $due = Article::query()
            ->whereNotNull('doi')->where('doi', '!=', '')
            ->where(fn ($q) => $q->whereNull('metrics_checked_at')->orWhere('metrics_checked_at', '<', now()->subDays(self::REFRESH_DAYS)))
            ->orderByRaw('metrics_checked_at IS NOT NULL, metrics_checked_at')
            ->limit($max)->get(['id', 'doi', 'zenodo_id']);

        foreach ($due as $article) {
            if (microtime(true) - $start > $seconds) {
                break;
            }
            $values = ['metrics_checked_at' => now()];
            try {
                $values += $this->zenodo($article) + ['citations' => max($this->datacite($article->doi), $this->openalex($article->doi))];
                $result['checked']++;
            } catch (Throwable $e) {
                $result['errors']++; // tried again next week; the old numbers stay
            }
            // Query builder: no "saved" hooks (IndexNow, topics) and no change to updated_at (sitemap lastmod).
            DB::table('article')->where('id', $article->id)->update($values);
        }

        return $result;
    }

    /** @return array{zenodo_views?: int, zenodo_downloads?: int} */
    private function zenodo(Article $article): array
    {
        $id = (string) $article->zenodo_id ?: (preg_match('/zenodo\.(\d+)$/', (string) $article->doi, $m) ? $m[1] : '');
        if ($id === '') {
            return [];
        }
        $response = Http::acceptJson()->timeout(15)->get('https://zenodo.org/api/records/'.$id);
        if ($response->status() === 429) {
            throw new \RuntimeException('Zenodo rate limit');
        }
        if (! $response->successful()) {
            return [];
        }

        return [
            'zenodo_views' => (int) $response->json('stats.unique_views'),
            'zenodo_downloads' => (int) $response->json('stats.unique_downloads'),
        ];
    }

    private function datacite(string $doi): int
    {
        $response = Http::acceptJson()->timeout(15)->get('https://api.datacite.org/dois/'.$doi);

        return $response->successful() ? (int) $response->json('data.attributes.citationCount') : 0;
    }

    private function openalex(string $doi): int
    {
        $response = Http::acceptJson()->timeout(15)->get('https://api.openalex.org/works/doi:'.$doi, ['select' => 'cited_by_count']);

        return $response->successful() ? (int) $response->json('cited_by_count') : 0;
    }
}
