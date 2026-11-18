<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\HasPdf;
use Illuminate\Database\Eloquent\Model;

/** A journal issue. */
class Maqola extends Model
{
    use HasFactory, HasPdf;

    protected $table = 'maqola';

    protected $fillable = ['volume', 'title', 'abstract', 'pdf', 'pdf_file_id', 'is_free'];

    protected $casts = ['is_free' => 'boolean'];

    private static ?Maqola $current = null;

    /** The newest issue: the one new submissions are meant for (same as "current issue" on the home page). */
    public static function current(): ?Maqola
    {
        return self::$current ??= static::latest('created_at')->latest('id')->first();
    }

    protected function pdfRoute(): string
    {
        return 'files.issue';
    }

    public function articles()
    {
        return $this->hasMany(Article::class, 'volume');
    }

    /** Conference proceedings (named after the conference) rather than a regular journal issue. */
    public function isProceedings(): bool
    {
        return (bool) preg_match('/konferens|anjuman|conference|proceedings|конференц|симпозиум|forum/iu', (string) $this->title);
    }

    /** "Vol. 2 No. 3 (2026) · 2026-yil noyabr soni": volume and issue name, as PDF headers show them. */
    public function label(): string
    {
        $volume = trim((string) $this->volume);
        $title = trim((string) $this->title);
        if ($volume === '' || $title === '' || str_contains($title, $volume)) {
            return $title ?: $volume;
        }

        return $volume.' · '.$title;
    }

}
