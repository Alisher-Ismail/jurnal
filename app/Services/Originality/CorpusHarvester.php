<?php

namespace App\Services\Originality;

use App\Models\ExternalWork;
use App\Models\PlagiarismDocument;
use App\Support\AppSetting;
use App\Support\SchemaState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds a free corpus of published Uzbek research from OpenAlex (https://openalex.org, open data, no key):
 * title, authors, journal, link and abstract fingerprints of every work, so submissions can be compared
 * with papers published anywhere. Runs in short batches (cron or the System page) and resumes where it stopped.
 */
class CorpusHarvester
{
    private const API = 'https://api.openalex.org/works';
    private const SELECT = 'id,doi,title,publication_year,authorships,primary_location,best_oa_location,abstract_inverted_index';

    public function __construct(private Fingerprinter $fingerprinter)
    {
    }

    /** Works by authors at Uzbek institutions, then works in Uzbek journals whose affiliations were not recorded. */
    public function passes(string $from): array
    {
        return [
            'affiliation' => "institutions.country_code:uz,has_abstract:true,from_publication_date:{$from}",
            'journals' => "primary_location.source.country_code:uz,institutions.country_code:!uz,has_abstract:true,from_publication_date:{$from}",
        ];
    }

    public function enabled(): bool
    {
        return (bool) config('journal.corpus.enabled', true) && SchemaState::corpusReady();
    }

    public function state(): array
    {
        return AppSetting::get('corpus', []) + [
            'mode' => 'full', 'pass' => 0, 'window' => null, 'page' => 1, 'totals' => [], 'pages' => 0,
            'completed_at' => null, 'last_run' => null, 'last_error' => null,
        ];
    }

    /**
     * Harvest pages until the time budget is used.
     *
     * OpenAlex only allows paging through 10,000 results without an API key (cursor paging needs a key),
     * so each pass is walked in 7-day publication-date windows, each far below that limit.
     *
     * @return array{works: int, pages: int, finished: bool, error: ?string}
     */
    public function harvest(float $budgetSeconds = 50): array
    {
        if (! $this->enabled()) {
            return ['works' => 0, 'pages' => 0, 'finished' => false, 'error' => 'disabled'];
        }
        @set_time_limit((int) $budgetSeconds + 60);
        $started = microtime(true);
        $state = $this->state();

        // After a complete harvest, refresh recent publications once a day.
        if ($state['mode'] === 'done') {
            if ($state['completed_at'] && now()->diffInHours(\Carbon\Carbon::parse($state['completed_at'])) < 24) {
                return ['works' => 0, 'pages' => 0, 'finished' => true, 'error' => null];
            }
            $state = ['mode' => 'update', 'pass' => 0, 'window' => null, 'page' => 1] + $state;
        }

        $from = $state['mode'] === 'update'
            ? now()->subDays(120)->startOfDay()
            : \Carbon\Carbon::create((int) config('journal.corpus.from_year', 2015), 1, 1);
        $passes = array_values($this->passes($from->toDateString()));
        $works = $pages = 0;
        $error = null;

        if ($state['mode'] === 'full' && ! $state['totals']) {
            $state['totals'] = $this->totals($passes);
        }
        $state['window'] ??= $from->toDateString();

        while (microtime(true) - $started < $budgetSeconds && $state['pass'] < count($passes)) {
            $start = \Carbon\Carbon::parse($state['window']);
            $end = $start->copy()->addDays(6);
            try {
                $response = Http::timeout(60)->retry(2, 3000)->get(self::API, $this->params([
                    'filter' => $passes[$state['pass']].',from_publication_date:'.$start->toDateString().',to_publication_date:'.$end->toDateString(),
                    'per-page' => 200,
                    'page' => $state['page'],
                    'select' => self::SELECT,
                ]));
            } catch (Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 250);
                break;
            }
            if (! $response->successful()) {
                $error = 'OpenAlex HTTP '.$response->status().': '.mb_substr((string) $response->json('message', ''), 0, 160);
                break;
            }

            $results = $response->json('results', []);
            $works += $this->store($results);
            $pages++;
            $state['pages']++;

            $seen = $state['page'] * 200;
            if (count($results) === 200 && $seen < (int) $response->json('meta.count') && $seen < 10000) {
                $state['page']++;
            } elseif ($end->lt(now())) {
                $state['window'] = $end->copy()->addDay()->toDateString();
                $state['page'] = 1;
            } else {
                $state['pass']++;
                $state['window'] = $from->toDateString();
                $state['page'] = 1;
            }
            AppSetting::put('corpus', $state + ['last_run' => now()->toDateTimeString()]);
        }

        $finished = $state['pass'] >= count($passes);
        if ($finished) {
            $state['mode'] = 'done';
            $state['completed_at'] = now()->toDateTimeString();
        }
        $state['last_run'] = now()->toDateTimeString();
        $state['last_error'] = $error;
        AppSetting::put('corpus', $state);

        if ($error) {
            Log::warning('Corpus harvest stopped', ['error' => $error]);
        }

        return ['works' => $works, 'pages' => $pages, 'finished' => $finished, 'error' => $error];
    }

    /** Common query parameters: polite-pool e-mail and, if configured, a free OpenAlex API key. */
    private function params(array $params): array
    {
        return $params + array_filter([
            'mailto' => config('journal.email') ?: config('mail.from.address'),
            'api_key' => config('journal.corpus.openalex_key'),
        ]);
    }

    /** Expected number of works per pass (for the progress bar). */
    private function totals(array $passes): array
    {
        $totals = [];
        foreach ($passes as $i => $filter) {
            try {
                $totals[$i] = (int) Http::timeout(30)->get(self::API, $this->params(['filter' => $filter, 'per-page' => 1, 'select' => 'id']))->json('meta.count');
            } catch (Throwable $e) {
                $totals[$i] = 0;
            }
        }

        return $totals;
    }

    /** Save a page of OpenAlex works and their (sampled) abstract fingerprints. */
    public function store(array $results): int
    {
        $sampling = max(1, (int) config('journal.corpus.sampling', 4));
        $stored = 0;
        $rows = [];
        $replace = [];

        foreach ($results as $w) {
            $abstract = $this->abstract($w['abstract_inverted_index'] ?? null);
            $title = trim((string) ($w['title'] ?? ''));
            if ($abstract === '' && $title === '') {
                continue;
            }
            $text = $title.'. '.$abstract;
            $hash = md5($text);

            $work = ExternalWork::updateOrCreate(['openalex_id' => basename((string) $w['id'])], [
                'doi' => $w['doi'] ?? null,
                'title' => mb_substr($title, 0, 600),
                'authors' => mb_substr(implode(', ', array_filter(array_map(fn ($a) => $a['author']['display_name'] ?? null, $w['authorships'] ?? []))), 0, 1000),
                'year' => $w['publication_year'] ?? null,
                'journal' => mb_substr((string) ($w['primary_location']['source']['display_name'] ?? ''), 0, 250) ?: null,
                'url' => mb_substr((string) ($w['primary_location']['landing_page_url'] ?? $w['doi'] ?? ''), 0, 600) ?: null,
                'pdf_url' => mb_substr((string) ($w['best_oa_location']['pdf_url'] ?? ''), 0, 600) ?: null,
            ]);

            $doc = PlagiarismDocument::firstOrNew(['type' => 'external', 'ref_id' => $work->id]);
            if ($doc->exists && $doc->source_hash === $hash) {
                continue; // unchanged since the last harvest
            }

            // Keep every n-th fingerprint (by hash value, so submissions sample the same ones):
            // a copied abstract still shares many, and the corpus stays small enough for shared hosting.
            $norm = $this->fingerprinter->words($text)['norm'];
            $fingerprints = array_values(array_filter(
                $this->fingerprinter->fingerprints($norm),
                fn ($f) => $f[0] % $sampling === 0
            ));
            $doc->fill([
                'title' => mb_substr($title, 0, 250),
                'authors' => mb_substr((string) $work->authors, 0, 500),
                'source_hash' => $hash,
                'words' => count($norm),
                'fingerprints' => count($fingerprints),
                'error' => null,
                'indexed_at' => now(),
            ])->save();

            $replace[] = $doc->id;
            foreach ($fingerprints as [$h, $position]) {
                $rows[] = ['document_id' => $doc->id, 'hash' => $h, 'position' => $position];
            }
            $stored++;
        }

        if ($replace) {
            DB::table('plagiarism_fingerprints')->whereIn('document_id', $replace)->delete();
        }
        foreach (array_chunk($rows, 2000) as $chunk) {
            DB::table('plagiarism_fingerprints')->insert($chunk);
        }

        return $stored;
    }

    /** OpenAlex ships abstracts as {word: [positions]}; rebuild the text. */
    public function abstract(?array $inverted): string
    {
        if (! $inverted) {
            return '';
        }
        $words = [];
        foreach ($inverted as $word => $positions) {
            foreach ($positions as $p) {
                $words[(int) $p] = $word;
            }
        }
        ksort($words);

        return implode(' ', $words);
    }

    public function stats(): array
    {
        if (! SchemaState::corpusReady()) {
            return ['ready' => false];
        }
        $state = $this->state();

        return [
            'ready' => true,
            'enabled' => $this->enabled(),
            'works' => ExternalWork::count(),
            'expected' => array_sum($state['totals']),
            'mode' => $state['mode'],
            'last_run' => $state['last_run'],
            'completed_at' => $state['completed_at'],
            'last_error' => $state['last_error'],
            'size_mb' => $this->sizeMb(),
        ];
    }

    private function sizeMb(): ?float
    {
        try {
            if (DB::getDriverName() !== 'mysql') {
                return null;
            }
            $row = DB::selectOne(
                "SELECT SUM(DATA_LENGTH + INDEX_LENGTH) AS b FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('plagiarism_fingerprints', 'plagiarism_documents', 'external_works')"
            );

            return $row && $row->b ? round($row->b / 1048576, 1) : 0.0;
        } catch (Throwable $e) {
            return null;
        }
    }
}
