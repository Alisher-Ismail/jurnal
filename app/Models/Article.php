<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\HasPdf;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    use HasFactory, HasPdf;

    protected $table = 'article';

    protected $fillable = ['volume', 'title', 'keywords', 'author', 'abstract', 'pdf', 'pdf_file_id', 'doi', 'zenodo_id', 'excerpt', 'text_checked_at'];

    protected $casts = ['text_checked_at' => 'datetime'];

    protected static function booted()
    {
        // New and changed articles are announced to Bing, Yandex & co. right away (IndexNow).
        static::saved(function (Article $article) {
            if ($article->wasRecentlyCreated || $article->wasChanged(['title', 'author', 'abstract', 'keywords', 'pdf', 'pdf_file_id', 'doi', 'excerpt'])) {
                \App\Services\IndexNow::queueArticle($article);
            }
            if ($article->wasRecentlyCreated || $article->wasChanged('keywords')) {
                \App\Services\TopicIndex::queueRebuild();
            }
        });
        static::deleted(fn () => \App\Services\TopicIndex::queueRebuild());
    }

    public function topics()
    {
        return $this->belongsToMany(Topic::class, 'article_topic');
    }

    /** The author agreed (when submitting) to CC BY 4.0 publication and deposit in Zenodo. */
    public function authorAgreedToZenodo(): bool
    {
        return (bool) $this->paper?->license_agreed_at;
    }

    /** https://doi.org/… link for the article's DOI, or null. */
    public function doiUrl(): ?string
    {
        return filled($this->doi) ? 'https://doi.org/'.$this->doi : null;
    }

    /** "volume" holds the id of the issue (maqola) the article belongs to. */
    public function issue()
    {
        return $this->belongsTo(Maqola::class, 'volume');
    }

    public function paper()
    {
        return $this->hasOne(Paper::class);
    }

    protected function pdfRoute(): string
    {
        return 'files.article';
    }

    /** Publication date: the issue's date (articles imported later keep their issue's date). */
    public function publishedAt(): ?\Illuminate\Support\Carbon
    {
        return $this->issue?->created_at ?? $this->created_at;
    }

    /** ISO 639-1 guess from the title's script and letters: uz, ru or en. */
    public function languageCode(): string
    {
        // Lower-cased (titles are often in capitals), with the abstract as extra evidence.
        $title = mb_strtolower((string) $this->title.' '.mb_substr((string) $this->abstract, 0, 400));
        if (preg_match('/\p{Cyrillic}/u', $title)) {
            return preg_match('/[ўқғҳ]/u', $title) ? 'uz' : 'ru';
        }
        if (preg_match('/[og][ʻ‘’\'`]|\b(va|bilan|ning|lari|larning|asosida|tizimi|rivojlantirish|iqtisodiy|sharoitida|yordamida)\b/u', $title)
            || preg_match('/(ning|lari|larni|lash|larida|dagi|ishlari)\b/u', $title)) {
            return 'uz';
        }

        return 'en';
    }

    public function authorList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->author))));
    }

    public function keywordList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;]/', self::cleanKeywords($this->keywords)))));
    }

    /** Authors often paste the label too: "Kalit so‘zlar: …", "Ключевые слова: …", "Keywords: …". */
    public static function cleanKeywords(?string $keywords): string
    {
        return trim((string) preg_replace(
            '/^\s*(kalit\s+so\W{0,2}zlar|tayanch\s+so\W{0,2}zlar|калит\s+с[ўу]злар|таянч\s+с[ўу]злар|ключевые\s+слова|key\s*words|keywords)\s*[:.\-–—]\s*/iu',
            '',
            (string) $keywords
        ), " \t\n.");
    }

    public function setKeywordsAttribute($value): void
    {
        $this->attributes['keywords'] = self::cleanKeywords($value);
    }

    /**
     * When the paper actually went online: the acceptance date of an online submission, otherwise the
     * issue's date. An issue may be opened before its papers arrive, and "Published" must not come
     * before "Received".
     */
    public function onlineAt(): ?\Illuminate\Support\Carbon
    {
        $issueDate = $this->publishedAt();
        $paperDate = $this->paper?->published_at;

        return $paperDate && (! $issueDate || $paperDate->gt($issueDate)) ? $paperDate : $issueDate;
    }

    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(function ($q) use ($like) {
            $q->where('title', 'like', $like)
                ->orWhere('author', 'like', $like)
                ->orWhere('keywords', 'like', $like);
        });
    }
}
