<?php

namespace App\Services\Originality;

use App\Models\Article;
use App\Models\Maqola;
use App\Models\Paper;
use App\Models\PlagiarismDocument;
use App\Services\FileVault;
use App\Support\SchemaState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Keeps fingerprints of everything the journal holds, so new submissions can be compared with it. */
class PlagiarismIndex
{
    /** Documents of this journal (the harvested corpus uses type 'external'). */
    public const ARCHIVE_TYPES = ['article', 'issue', 'paper'];

    public function __construct(private TextExtractor $extractor, private Fingerprinter $fingerprinter, private FileVault $vault)
    {
    }

    /**
     * Index new or changed documents until the time budget runs out (shared hosting limits request time).
     *
     * @return array{indexed: int, pending: int, total: int}
     */
    public function sync(float $budgetSeconds = 40, bool $retryFailed = false): array
    {
        // One indexer at a time: the cron run and a submission's check used to index the same
        // document together and the second insert failed ("Duplicate entry 'article-1110'").
        $lock = Cache::lock('plagiarism-index-sync', (int) ceil($budgetSeconds) + 300);
        if (! $lock->get()) {
            return ['indexed' => 0, 'pending' => 0, 'total' => 0, 'busy' => true];
        }

        try {
            return $this->syncLocked($budgetSeconds, $retryFailed);
        } finally {
            $lock->release();
        }
    }

    private function syncLocked(float $budgetSeconds, bool $retryFailed): array
    {
        $started = microtime(true);
        $candidates = $this->candidates();
        $this->forgetRemoved($candidates);

        $existing = PlagiarismDocument::whereIn('type', self::ARCHIVE_TYPES)->get()->keyBy(fn ($d) => $d->type.':'.$d->ref_id);
        $indexed = 0;
        $pending = [];
        foreach ($candidates as $key => $candidate) {
            $doc = $existing[$key] ?? null;
            $failed = $doc && $doc->error && (! $doc->indexed_at || ! $doc->words);
            if ($doc && $doc->source_hash === $candidate['hash'] && ($doc->indexed_at || $doc->error) && ! ($retryFailed && $failed)) {
                continue;
            }
            // Indexed while on the server, then moved to Telegram (same file): keep it, no re-download.
            if ($doc && $doc->indexed_at && $doc->words && ! $doc->error
                && str_starts_with($candidate['hash'], 'tg:') && preg_match('/^[0-9a-f]{32}$/', (string) $doc->source_hash)) {
                $doc->source_hash = $candidate['hash'];
                $doc->saveQuietly();

                continue;
            }
            $pending[] = $candidate;
        }

        foreach ($pending as $i => $candidate) {
            if (microtime(true) - $started > $budgetSeconds) {
                break;
            }
            $this->index($candidate);
            $indexed++;
            unset($pending[$i]);
        }

        return ['indexed' => $indexed, 'pending' => count($pending), 'total' => count($candidates)];
    }

    /** @param array{type: string, ref_id: int, title: string, path: ?string, hash: string} $candidate */
    public function index(array $candidate): PlagiarismDocument
    {
        $doc = PlagiarismDocument::firstOrNew(['type' => $candidate['type'], 'ref_id' => $candidate['ref_id']]);
        $doc->title = mb_substr($candidate['title'], 0, 250);
        $doc->authors = isset($candidate['authors']) ? mb_substr((string) $candidate['authors'], 0, 500) : null;
        $doc->source_hash = $candidate['hash'];

        try {
            $path = $candidate['path'] ?? null;
            if (! $path && ! empty($candidate['file_id'])) {
                $path = $this->vault->telegramCopy($candidate['file_id']);
            }
            $text = $path ? $this->extractor->extract($path) : '';
            $words = $this->fingerprinter->words($text)['norm'];
            $fingerprints = $this->fingerprinter->fingerprints($words);

            $doc->words = count($words);
            $doc->fingerprints = count($fingerprints);
            $doc->setText($text); // kept (compressed) to read author names next to a matched passage
            $doc->error = $words ? null : 'No text could be read (scanned images?)';
            $doc->indexed_at = now();
            $doc->save();
            $this->store($doc->id, $fingerprints);
        } catch (Throwable $e) {
            Log::warning('Plagiarism indexing failed', ['doc' => $candidate['type'].'#'.$candidate['ref_id'], 'error' => $e->getMessage()]);
            $doc->error = mb_substr(class_basename($e).': '.$e->getMessage(), 0, 250);
            $doc->words = 0;
            $doc->fingerprints = 0;
            $doc->indexed_at = null;
            $doc->save();
            $this->store($doc->id, []);
        }

        return $doc;
    }

    public function indexPaper(Paper $paper, string $text): void
    {
        $doc = PlagiarismDocument::firstOrNew(['type' => 'paper', 'ref_id' => $paper->id]);
        $fingerprints = $this->fingerprinter->fingerprints($this->fingerprinter->words($text)['norm']);
        $doc->setText($text);
        $doc->fill([
            'title' => mb_substr($paper->title, 0, 250),
            'authors' => mb_substr((string) $paper->authors, 0, 500),
            'source_hash' => $this->paperHash($paper),
            'words' => count($this->fingerprinter->words($text)['norm']),
            'fingerprints' => count($fingerprints),
            'error' => null,
            'indexed_at' => now(),
        ])->save();
        $this->store($doc->id, $fingerprints);
    }

    public function stats(): array
    {
        if (! SchemaState::originalityReady()) {
            return ['documents' => 0, 'failed' => 0, 'fingerprints' => 0, 'total' => 0, 'ready' => false];
        }

        return [
            'ready' => true,
            'documents' => PlagiarismDocument::whereIn('type', self::ARCHIVE_TYPES)->whereNotNull('indexed_at')->whereNull('error')->where('words', '>', 0)->count(),
            // Failed = an error and no completed indexing (an earlier run may have left old word counts behind).
            'failed' => $this->failedQuery()->count(),
            'problems' => $this->failedQuery()->get(['type', 'ref_id', 'title', 'error'])->toArray(),
            // Summed per document: counting rows of a table with millions of corpus fingerprints is slow.
            'fingerprints' => (int) PlagiarismDocument::whereIn('type', self::ARCHIVE_TYPES)->sum('fingerprints'),
            'total' => count($this->candidates()) + PlagiarismDocument::where('type', 'paper')->count(),
        ];
    }

    private function failedQuery()
    {
        return PlagiarismDocument::whereIn('type', self::ARCHIVE_TYPES)->whereNotNull('error')->where(fn ($q) => $q->whereNull('indexed_at')->orWhere('words', 0));
    }

    /** @return array<string, array{type: string, ref_id: int, title: string, path: ?string, hash: string}> */
    private function candidates(): array
    {
        $list = [];
        foreach (Article::all() as $a) {
            if ($a->pdfUrl()) {
                $list['article:'.$a->id] = $this->candidate('article', $a->id, (string) $a->title, $a->pdf_file_id, $a->pdf) + ['authors' => $a->author];
            }
        }
        foreach (Maqola::all() as $m) {
            if ($m->pdfUrl()) {
                $list['issue:'.$m->id] = $this->candidate('issue', $m->id, (string) $m->title, $m->pdf_file_id, $m->pdf);
            }
        }
        // Submissions are indexed by OriginalityService when they are checked (it already has their text).

        return $list;
    }

    /** Cheap description of a document; Telegram files are only downloaded when actually indexed. */
    private function candidate(string $type, int $id, string $title, ?string $fileId, ?string $pdf): array
    {
        $local = $fileId ? null : $this->vault->localPath($pdf, 'public');

        return [
            'type' => $type, 'ref_id' => $id, 'title' => $title, 'path' => $local, 'file_id' => $fileId,
            // Telegram file ids are longer than the 64-character column: store their md5.
            'hash' => $fileId ? 'tg:'.md5($fileId) : ($local ? md5($local.'|'.filesize($local).'|'.filemtime($local)) : 'missing'),
        ];
    }

    private function paperHash(Paper $paper): string
    {
        return 'paper:'.md5((string) ($paper->file_id ?: $paper->file_path));
    }

    /** Drop index entries of deleted issues, articles and submissions. Harvested corpus works are never touched. */
    private function forgetRemoved(array $candidates): void
    {
        foreach (PlagiarismDocument::whereIn('type', self::ARCHIVE_TYPES)->get() as $doc) {
            $gone = $doc->type === 'paper'
                ? ! Paper::whereKey($doc->ref_id)->exists()
                : ! isset($candidates[$doc->type.':'.$doc->ref_id]);
            if ($gone) {
                $this->forget($doc);
            }
        }
    }

    public function forget(PlagiarismDocument $doc): void
    {
        DB::table('plagiarism_fingerprints')->where('document_id', $doc->id)->delete();
        $doc->delete();
    }

    private function store(int $documentId, array $fingerprints): void
    {
        DB::table('plagiarism_fingerprints')->where('document_id', $documentId)->delete();
        foreach (array_chunk($fingerprints, 2000) as $chunk) {
            DB::table('plagiarism_fingerprints')->insert(array_map(
                fn ($f) => ['document_id' => $documentId, 'hash' => $f[0], 'position' => $f[1]],
                $chunk
            ));
        }
    }
}
