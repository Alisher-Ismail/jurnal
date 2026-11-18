<?php

namespace App\Services\Originality;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Searches distinctive sentences of a manuscript on the web as exact phrases (Brave Search API).
 * Each sentence costs one query; a monthly cap keeps usage within the free credit.
 */
class WebChecker
{
    public function enabled(): bool
    {
        return filled(config('services.brave.key'));
    }

    public function usedThisMonth(): int
    {
        return (int) Cache::get($this->usageKey(), 0);
    }

    public function monthlyLimit(): int
    {
        return (int) config('journal.web_check_monthly_limit', 900);
    }

    /** @return array{checked: int, found: int, matches: array, note: ?string} */
    public function check(string $text): array
    {
        if (! $this->enabled()) {
            return ['checked' => 0, 'found' => 0, 'matches' => [], 'note' => 'not_configured'];
        }

        $sentences = $this->pickSentences($text, (int) config('journal.web_check_sentences', 8));
        $checked = $found = 0;
        $matches = [];
        $note = null;
        $ownHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'journal.emro.uz';

        foreach ($sentences as $sentence) {
            if ($this->usedThisMonth() >= $this->monthlyLimit()) {
                $note = 'monthly_limit';
                break;
            }
            try {
                $response = Http::timeout(15)->withHeaders([
                    'X-Subscription-Token' => config('services.brave.key'),
                    'Accept' => 'application/json',
                ])->get('https://api.search.brave.com/res/v1/web/search', [
                    'q' => '"'.$sentence['query'].'"',
                    'count' => 5,
                ]);
                Cache::increment($this->usageKey()) ?: Cache::put($this->usageKey(), 1, now()->addDays(40));
            } catch (Throwable $e) {
                $note = 'error';
                break;
            }
            if ($response->status() === 429) {
                $note = 'rate_limited';
                break;
            }
            if (! $response->successful()) {
                $note = 'error';
                break;
            }

            $checked++;
            $results = collect($response->json('web.results', []))
                ->reject(fn ($r) => str_contains((string) parse_url($r['url'] ?? '', PHP_URL_HOST), $ownHost))
                ->take(3)
                ->map(fn ($r) => ['title' => strip_tags((string) ($r['title'] ?? '')), 'url' => (string) ($r['url'] ?? '')])
                ->values()->all();
            if ($results) {
                $found++;
                $matches[] = ['sentence' => $sentence['sentence'], 'results' => $results];
            }
            usleep(1100000); // Brave allows 1 request per second on the free credit
        }

        return ['checked' => $checked, 'found' => $found, 'matches' => $matches, 'note' => $note];
    }

    /**
     * Long, ordinary prose sentences spread through the text (references and tables skipped).
     *
     * @return array<int, array{sentence: string, query: string}>
     */
    public function pickSentences(string $text, int $count): array
    {
        $text = preg_replace('/\s+/u', ' ', $text);
        // Stop at the reference list: those lines are meant to match other sources.
        if (preg_match('/\b(Adabiyotlar|Foydalanilgan adabiyotlar|Литература|Список литературы|References|Bibliography)\b/iu', $text, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > strlen($text) * 0.4) {
            $text = substr($text, 0, $m[0][1]);
        }

        $candidates = [];
        foreach (preg_split('/(?<=[.!?])\s+/u', $text) as $sentence) {
            $words = preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY);
            $letters = preg_match_all('/\p{L}/u', $sentence);
            if (count($words) < 12 || count($words) > 45 || $letters < mb_strlen($sentence) * 0.7 || preg_match('#https?://|www\.#', $sentence)) {
                continue;
            }
            // A run of 12 words from the middle: long enough to be distinctive, short enough to survive small edits.
            $offset = (int) max(0, floor((count($words) - 12) / 2));
            $query = trim(implode(' ', array_slice($words, $offset, 12)), " ,;:()\"'«»“”-–—.");
            $candidates[] = ['sentence' => trim($sentence), 'query' => $query];
        }
        if (count($candidates) <= $count) {
            return $candidates;
        }

        // Evenly spread through the document.
        $picked = [];
        $step = count($candidates) / $count;
        for ($i = 0; $i < $count; $i++) {
            $picked[] = $candidates[(int) floor($i * $step + $step / 2)];
        }

        return $picked;
    }

    private function usageKey(): string
    {
        return 'web-check-usage:'.now()->format('Y-m');
    }
}
