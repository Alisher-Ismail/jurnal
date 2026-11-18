<?php

namespace App\Services;

use App\Models\Article;
use App\Services\Originality\TextExtractor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Fills empty abstracts from the articles' PDFs, a batch at a time (shared hosting has short time limits).
 * A labelled abstract becomes the article's abstract (and keywords, if empty); papers without one get
 * the opening of their text as an excerpt, which Google Scholar accepts in place of an abstract.
 */
class AbstractFiller
{
    public function __construct(private FileVault $vault, private TextExtractor $text, private AbstractExtractor $extractor)
    {
    }

    public function ready(): bool
    {
        return Schema::hasColumn('article', 'text_checked_at');
    }

    private function missing(): Builder
    {
        return Article::query()->where(fn ($q) => $q->whereNull('abstract')->orWhereRaw("TRIM(abstract) = ''"));
    }

    public function stats(): array
    {
        return [
            'total' => Article::count(),
            'missing' => $this->missing()->count(),
            'excerpts' => $this->missing()->whereNotNull('excerpt')->count(),
            'remaining' => $this->missing()->whereNull('text_checked_at')->count(),
        ];
    }

    /** @return array{abstracts: int, excerpts: int, none: int, failed: int} */
    public function run(int $limit = 25): array
    {
        @set_time_limit(300);
        $result = ['abstracts' => 0, 'excerpts' => 0, 'none' => 0, 'failed' => 0];

        foreach ($this->missing()->whereNull('text_checked_at')->orderBy('id')->limit($limit)->get() as $article) {
            try {
                $source = $article->pdf_file_id ? $this->vault->telegramCopy($article->pdf_file_id) : $this->vault->localPath($article->pdf, 'public');
                if (! $source || FileVault::detectExtension($source) !== 'pdf') {
                    $result['none']++;
                } else {
                    $text = $this->text->extract($source);
                    if ($found = $this->extractor->find($text, (string) $article->title)) {
                        $article->abstract = $found['abstract'];
                        if (! trim((string) $article->keywords) && $found['keywords']) {
                            $article->keywords = $found['keywords'];
                        }
                        $result['abstracts']++;
                    } elseif ($excerpt = $this->extractor->excerpt($text, (string) $article->title)) {
                        $article->excerpt = $excerpt;
                        $result['excerpts']++;
                    } else {
                        $result['none']++;
                    }
                }
            } catch (Throwable $e) {
                $result['failed']++;
                Log::warning('Abstract extraction failed', ['article' => $article->id, 'error' => $e->getMessage()]);
            }
            $article->text_checked_at = now();
            $article->save();
        }

        return $result;
    }
}
