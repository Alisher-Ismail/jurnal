<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * IndexNow: tells Bing, Yandex, Seznam, Naver (and the engines that use them: DuckDuckGo, Yahoo, Ecosia)
 * right away that pages were published or changed, instead of waiting for their crawlers.
 * The key file public/{key}.txt proves the site is ours. Failures are logged, never thrown.
 */
class IndexNow
{
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    public function key(): ?string
    {
        $key = (string) config('journal.indexnow_key');

        return preg_match('/^[a-f0-9]{32}$/', $key) && is_file(public_path($key.'.txt')) ? $key : null;
    }

    /**
     * @param  string[]  $urls  absolute URLs on this site
     * @return array{sent: int, status: ?int}
     */
    public function submit(array $urls): array
    {
        $key = $this->key();
        $urls = array_values(array_unique(array_filter($urls, fn ($u) => is_string($u) && str_starts_with($u, url('/')))));
        if (! $key || ! $urls) {
            return ['sent' => 0, 'status' => null];
        }

        $status = null;
        foreach (array_chunk($urls, 10000) as $chunk) {
            try {
                $response = Http::timeout(30)->acceptJson()->post(self::ENDPOINT, [
                    'host' => parse_url(url('/'), PHP_URL_HOST),
                    'key' => $key,
                    'keyLocation' => url($key.'.txt'),
                    'urlList' => $chunk,
                ]);
                $status = $response->status();
                if (! in_array($status, [200, 202], true)) {
                    Log::warning('IndexNow rejected the URLs', ['status' => $status, 'body' => mb_substr($response->body(), 0, 300)]);
                }
            } catch (Throwable $e) {
                Log::warning('IndexNow failed', ['error' => $e->getMessage()]);
            }
        }

        return ['sent' => count($urls), 'status' => $status];
    }

    private static array $queued = [];

    /**
     * Queue an article for notification once the response has been sent (publishing must not wait
     * for the search engines). Many saves in one request are sent together.
     */
    public static function queueArticle(\App\Models\Article $article): void
    {
        if (! (string) config('journal.indexnow_key') || app()->runningUnitTests()) {
            return;
        }
        if (! self::$queued) {
            app()->terminating(function () {
                $ids = array_keys(self::$queued);
                self::$queued = [];
                $service = app(self::class);
                $urls = [];
                foreach (\App\Models\Article::whereIn('id', $ids)->get() as $a) {
                    $urls = array_merge($urls, $service->articleUrls($a));
                }
                $service->submit($urls);
            });
        }
        self::$queued[$article->id] = true;
    }

    /** An article's page (all languages) and its PDF. */
    public function article(\App\Models\Article $article): void
    {
        $this->submit($this->articleUrls($article));
    }

    public function articleUrls(\App\Models\Article $article): array
    {
        $urls = [];
        foreach (\App\Support\Locale::all() as $locale) {
            $urls[] = \App\Support\Locale::route('maqola', $article->id, $locale);
        }
        if ($article->issue_id ?? $article->volume) {
            $urls[] = \App\Support\Locale::route('maqolavolume', $article->volume, 'uz');
        }
        $urls[] = route('files.article', $article->id);
        $urls[] = \App\Support\Locale::route('maqolabosh', [], 'uz');

        return $urls;
    }

    /** Every URL listed in the sitemap. */
    public function everything(): array
    {
        $xml = app(\App\Http\Controllers\SitemapController::class)->__invoke()->getContent();
        preg_match_all('#<loc>([^<]+)</loc>#', (string) $xml, $m);

        return $this->submit(array_map('html_entity_decode', $m[1]));
    }
}
