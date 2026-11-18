<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Maqola;
use App\Services\FileVault;
use App\Services\PdfStamper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Serves published PDFs with the journal header/footer stamped on every page.
 * Files come from Telegram or the server disk; if a PDF cannot be stamped, the original is served.
 */
class FileController extends Controller
{
    public function article($id, FileVault $vault, PdfStamper $stamper)
    {
        $article = Article::with(['issue', 'paper'])->findOrFail($id);

        return $this->serve($article, [
            // "Emro Science and Education · Vol. 2 No. 3 (2026)"
            'header' => $article->issue?->label() ?? (string) $article->volume,
            'footer' => $this->footer($article->created_at, route('maqola', $article->id)),
            'url' => route('maqola', $article->id),
            'meta' => $this->articleMeta($article),
        ], $vault, $stamper);
    }

    /** First-page information in the style of international journals. */
    private function articleMeta(Article $article): array
    {
        $issue = $article->issue;
        $published = $article->onlineAt();
        $paper = $article->paper;
        $volume = trim((string) $issue?->volume);
        // "Vol. 2 No. 3 (2026)" → "2(3)" for the reference.
        $short = preg_match('/vol\.?\s*(\d+)\D+(\d+)/iu', $volume, $m) ? $m[1].'('.$m[2].')' : $volume;

        return [
            'title' => trim((string) $article->title),
            'authors' => implode(', ', $article->authorList()),
            'keywords' => implode('; ', $article->keywordList()),
            'type' => $issue?->isProceedings() ? 'Conference paper' : 'Research article',
            // "2(3) (2026)" after the journal name in the top line.
            'short' => trim(($short !== '' && $short !== $volume ? $short.' ' : '').'('.($published?->format('Y') ?? date('Y')).')'),
            'issue' => $issue?->label(),
            'published' => $published?->format('d.m.Y'),
            'year' => $published?->format('Y') ?? date('Y'),
            'received' => $paper?->created_at?->format('d.m.Y'),
            'accepted' => ($paper?->reviewed_at ?? $paper?->published_at)?->format('d.m.Y'),
            'doi' => $article->doi ?: null,
            'doi_url' => $article->doiUrl(),
            'citation' => implode(', ', $article->authorList()).' ('.($published?->format('Y') ?? date('Y')).'). '
                .Str::limit(trim((string) $article->title), 220).'. '.config('journal.name')
                .($short !== '' ? ', '.$short : '').'. '.($article->doiUrl() ?: route('maqola', $article->id)),
        ];
    }

    public function issue($id, FileVault $vault, PdfStamper $stamper)
    {
        $issue = Maqola::findOrFail($id);

        return $this->serve($issue, [
            'header' => $issue->label(),
            'footer' => $this->footer($issue->created_at, route('maqolavolume', $issue->id)),
            'url' => route('maqolavolume', $issue->id),
        ], $vault, $stamper);
    }

    /** Old direct links (/storage/pdfs/<name>.pdf, indexed by Google Scholar) get the stamped copy too. */
    public function legacy(string $file, FileVault $vault)
    {
        $path = 'pdfs/'.$file;
        if ($article = Article::where('pdf', $path)->first()) {
            return redirect()->route('files.article', $article->id, 301);
        }
        if ($issue = Maqola::where('pdf', $path)->first()) {
            return redirect()->route('files.issue', $issue->id, 301);
        }
        // Stored with another folder prefix (e.g. "public/pdfs/…"); the server copy may now be only in Telegram.
        $suffix = '%/'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], basename($file));
        if ($article = Article::where('pdf', 'like', $suffix)->first()) {
            return redirect()->route('files.article', $article->id, 301);
        }
        if ($issue = Maqola::where('pdf', 'like', $suffix)->first()) {
            return redirect()->route('files.issue', $issue->id, 301);
        }

        return $vault->download(null, $path, basename($file), 'public', true);
    }

    private function serve($record, array $text, FileVault $vault, PdfStamper $stamper)
    {
        $name = (Str::slug(Str::limit((string) $record->title, 80, '')) ?: 'document').'.pdf';
        $source = $record->pdf_file_id ? $vault->telegramCopy($record->pdf_file_id) : $vault->localPath($record->pdf, 'public');

        if ($source && FileVault::detectExtension($source) !== 'pdf') {
            // Not actually a PDF (e.g. a DOCX published by mistake): offer it under its real type.
            $real = FileVault::nameFor($source, $name);

            return response()->download($source, $real, [], 'attachment');
        }

        if ($source) {
            try {
                if ($stamped = $stamper->stamp($source, $text)) {
                    return response()->file($stamped, [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => 'inline; filename="'.$name.'"',
                    ]);
                }
            } catch (Throwable $e) {
                Log::warning('PDF stamping failed, serving original', ['record' => get_class($record).'#'.$record->id, 'error' => $e->getMessage()]);
            }
        }

        return $vault->download($record->pdf_file_id, $record->pdf, $name, 'public', true);
    }

    private function footer($date, string $url): string
    {
        return ($date ? 'Published '.$date->format('d.m.Y').' · ' : '').$url;
    }
}
