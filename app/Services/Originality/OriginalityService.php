<?php

namespace App\Services\Originality;

use App\Models\Paper;
use App\Models\PaperCheck;
use App\Models\PlagiarismDocument;
use App\Services\CertificateService;
use App\Services\PaperService;
use App\Services\Telegram\TelegramApi;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Runs the originality check of a submission: archive similarity, web search and the AI indicator. */
class OriginalityService
{
    public function __construct(
        private TextExtractor $extractor,
        private PlagiarismIndex $index,
        private SimilarityChecker $similarity,
        private WebChecker $web,
        private AiTextAnalyzer $ai,
        private AuthorshipChecker $authorship,
        private TelegramApi $telegram,
    ) {
    }

    public function run(Paper $paper, bool $notify = true): PaperCheck
    {
        @set_time_limit(600);
        $check = PaperCheck::firstOrNew(['paper_id' => $paper->id]);
        $check->fill(['status' => 'running', 'error' => null])->save();

        try {
            $path = app(PaperService::class)->manuscriptPath($paper);
            $text = $path ? $this->extractor->extract($path) : '';
            $words = count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
            if ($words < 50) {
                throw new \RuntimeException('The manuscript text could not be read (scanned images or an empty file).');
            }

            // Bring the archive index up to date first (limited time; the rest continues on the next run).
            // A problem with an archive file must not fail the author's check: compare with what is indexed.
            try {
                $this->index->sync(30);
            } catch (Throwable $e) {
                Log::warning('Plagiarism index sync failed', ['paper' => $paper->code, 'error' => $e->getMessage()]);
            }

            $exclude = PlagiarismDocument::where(function ($q) use ($paper) {
                $q->where(['type' => 'paper', 'ref_id' => $paper->id]);
                if ($paper->article_id) {
                    $q->orWhere(fn ($w) => $w->where(['type' => 'article', 'ref_id' => $paper->article_id]));
                }
            })->pluck('id')->all();

            $similarity = $this->similarity->check($text, $exclude);
            $web = $this->web->check($text);
            $ai = $this->ai->analyze($text);
            $conflict = $this->authorship->analyze($paper, $text, $similarity, $web);

            $check->fill([
                'status' => 'done',
                'words' => $words,
                'similarity' => $similarity['similarity'],
                'sources' => $similarity['sources'],
                'passages' => $similarity['passages'],
                'web_checked' => $web['checked'],
                'web_found' => $web['found'],
                'web_matches' => $web['matches'],
                'web_note' => $web['note'],
                'ai_score' => $ai['score'],
                'ai_signals' => $ai['signals'],
                'conflict' => $conflict,
                'checked_at' => now(),
            ])->save();

            // Later submissions are compared with this one too (duplicate submissions).
            // The check itself is done and saved: an indexing problem must not turn it into "failed".
            try {
                $this->index->indexPaper($paper, $text);
            } catch (Throwable $e) {
                Log::warning('Indexing the submission failed', ['paper' => $paper->code, 'error' => $e->getMessage()]);
            }
        } catch (Throwable $e) {
            Log::warning('Originality check failed', ['paper' => $paper->code, 'error' => $e->getMessage()]);
            $check->fill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'checked_at' => now()])->save();
        }

        $this->settleCertificate($paper->fresh(), $check);

        if ($notify) {
            $this->notifyEditors($paper, $check);
        }

        return $check;
    }

    /**
     * With "certificate after check", the submission certificate is issued only when the check found
     * no authorship conflict; otherwise it waits for an editor (Panel → submission → Issue certificate).
     */
    private function settleCertificate(Paper $paper, PaperCheck $check): void
    {
        if (! config('journal.certificate_after_check') || $paper->submissionCertificate()->exists()) {
            return;
        }
        if (\App\Models\Paper::certificateNeedsPayment()) {
            // No submission certificate: the only one is sent after acceptance and payment.
            // The check result still reaches the editors (notifyEditors).
            return;
        }
        $blocked = $check->status !== 'done' || ($check->conflict['hold'] ?? false);

        if ($blocked) {
            if (! $check->certificate_held) {
                $check->update(['certificate_held' => true]);
                app(PaperService::class)->notifyAuthor($paper, 'held');
            }

            return;
        }

        $check->update(['certificate_held' => false]);
        app(PaperService::class)->notifyAuthor($paper, 'submitted', app(CertificateService::class)->forSubmission($paper));
    }

    /** An editor decided the paper is fine: issue the held certificate. */
    public function releaseCertificate(Paper $paper): void
    {
        PaperCheck::where('paper_id', $paper->id)->update(['certificate_held' => false]);
        app(PaperService::class)->notifyAuthor($paper, 'submitted', app(CertificateService::class)->forSubmission($paper));
    }

    public function summary(PaperCheck $check, ?string $locale = null): string
    {
        $locale = $locale ?? config('journal.admin_locale');
        if ($check->status !== 'done') {
            return __('Originality check failed', [], $locale);
        }

        return __('Archive similarity', [], $locale).': '.$check->similarity.'%'
            .($check->web_checked ? ' · '.__('Web', [], $locale).': '.$check->web_found.'/'.$check->web_checked : '')
            .' · '.__('AI indicator', [], $locale).': '.($check->ai_score === null ? '—' : $check->ai_score.'/100');
    }

    private function notifyEditors(Paper $paper, PaperCheck $check): void
    {
        if (! $this->telegram->enabled()) {
            return;
        }
        $locale = config('journal.admin_locale');
        $warning = match ($check->conflictType()) {
            'mismatch' => "\n⚠️ <b>".e(__('Authorship conflict: this text matches work of other authors.', [], $locale)).'</b>',
            'web' => "\n⚠️ <b>".e(__('Most checked sentences were found on the web.', [], $locale)).'</b>',
            'same_authors' => "\nℹ️ ".e(__('Matches an earlier text by the same authors.', [], $locale)),
            default => '',
        };
        $held = $check->certificate_held ? "\n⏸ ".e(__('Certificate is held until an editor releases it.', [], $locale)) : '';
        $text = '🔎 <b>'.$paper->code.'</b> · '.e($this->summary($check)).$warning.$held."\n".route('admin.papers.show', $paper);
        foreach (config('services.telegram.admin_chat_ids', []) as $chatId) {
            try {
                $this->telegram->sendMessage($chatId, $text);
            } catch (Throwable $e) {
                Log::warning('Originality notification failed', ['chat' => $chatId, 'error' => $e->getMessage()]);
            }
        }
    }
}
