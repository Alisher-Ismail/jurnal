<?php

namespace App\Services;

use App\Models\Article;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Builds the topics (keyword pages) from the articles' keywords. Spellings of the same keyword meet
 * on one page: Latin and Cyrillic Uzbek ("raqamli iqtisodiyot" = "рақамли иқтисодиёт"), and Russian
 * words written the same way ("экспорт" = "eksport"). Rebuilt in full (it takes well under a second)
 * after an article changes.
 */
class TopicIndex
{
    private const CYRILLIC = [
        'ў' => 'o', 'қ' => 'q', 'ғ' => 'g', 'ҳ' => 'h', 'ё' => 'yo', 'ю' => 'yu', 'я' => 'ya', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sh', 'ж' => 'j', 'х' => 'x', 'й' => 'y', 'ы' => 'i', 'э' => 'e', 'ъ' => '', 'ь' => '',
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'з' => 'z', 'и' => 'i', 'к' => 'k',
        'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f',
    ];

    private static bool $queued = false;

    /** The address part of a keyword: "Sun’iy intellekt" → "suniy-intellekt", "Экспорт" → "eksport" ('' when unusable). */
    public static function slug(string $keyword): string
    {
        $k = mb_strtolower(trim($keyword));
        $k = strtr($k, self::CYRILLIC);
        $k = preg_replace("/[‘’ʻʼ`´'\"«»]/u", '', $k);
        $k = trim(preg_replace('/[^a-z0-9]+/', '-', $k), '-');
        $length = strlen($k);

        return $length >= 3 && $length <= 100 && ! ctype_digit(str_replace('-', '', $k)) ? $k : '';
    }

    /** Rebuild once, after the response, however many articles a request saved. */
    public static function queueRebuild(): void
    {
        if (self::$queued) {
            return;
        }
        self::$queued = true;
        app()->terminating(function () {
            try {
                app(self::class)->rebuild();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** @return int the number of topics */
    public function rebuild(): int
    {
        if (! Schema::hasTable('topics')) {
            return 0;
        }
        $topics = []; // slug => ['forms' => [form => count], 'articles' => [id => true]]
        foreach (Article::query()->select(['id', 'keywords'])->whereNotNull('keywords')->where('keywords', '!=', '')->cursor() as $article) {
            foreach ($article->keywordList() as $keyword) {
                $form = trim(preg_replace('/\s+/u', ' ', $keyword), " .\t\"'«»");
                if (mb_strlen($form) > 110 || ($slug = self::slug($form)) === '') {
                    continue;
                }
                $topics[$slug]['forms'][mb_strtolower($form)] = ($topics[$slug]['forms'][mb_strtolower($form)] ?? 0) + 1;
                $topics[$slug]['articles'][$article->id] = true;
            }
        }

        $now = now();
        DB::transaction(function () use ($topics, $now) {
            $existing = DB::table('topics')->pluck('id', 'slug');
            foreach (array_chunk($topics, 500, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $slug => $t) {
                    $rows[] = ['slug' => $slug, 'name' => $this->displayName($t['forms']), 'articles_count' => count($t['articles']),
                        'created_at' => $now, 'updated_at' => $now];
                }
                // updated_at (the sitemap's lastmod) changes only when the topic gains or loses articles.
                DB::table('topics')->upsert($rows, ['slug'], [
                    'updated_at' => DB::raw('IF(`articles_count` <> VALUES(`articles_count`), VALUES(`updated_at`), `updated_at`)'),
                    'name', 'articles_count',
                ]);
            }
            $ids = DB::table('topics')->pluck('id', 'slug');
            DB::table('article_topic')->delete();
            $pivot = [];
            foreach ($topics as $slug => $t) {
                foreach (array_keys($t['articles']) as $articleId) {
                    $pivot[] = ['article_id' => $articleId, 'topic_id' => $ids[$slug]];
                }
            }
            foreach (array_chunk($pivot, 1000) as $chunk) {
                DB::table('article_topic')->insert($chunk);
            }
            $gone = $existing->reject(fn ($id, $slug) => isset($topics[$slug]))->values();
            foreach ($gone->chunk(500) as $chunk) {
                DB::table('topics')->whereIn('id', $chunk->all())->delete();
            }
        });
        Cache::forget('sitemap.xml');

        return count($topics);
    }

    /** The spelling most articles use; Latin before Cyrillic when both are common. First letter capital. */
    private function displayName(array $forms): string
    {
        uksort($forms, function ($a, $b) use ($forms) {
            if ($forms[$a] !== $forms[$b]) {
                return $forms[$b] <=> $forms[$a];
            }

            return preg_match('/\p{Cyrillic}/u', $a) <=> preg_match('/\p{Cyrillic}/u', $b);
        });
        $latin = array_filter(array_keys($forms), fn ($f) => ! preg_match('/\p{Cyrillic}/u', $f));
        $best = array_key_first($forms);
        // Uzbek Latin is the site's main spelling: prefer it unless the Cyrillic form is clearly more common.
        if ($latin && preg_match('/\p{Cyrillic}/u', $best) && $forms[reset($latin)] * 2 >= $forms[$best]) {
            $best = reset($latin);
        }
        return mb_strtoupper(mb_substr($best, 0, 1)).mb_substr($best, 1);
    }
}
