<?php

namespace App\Services;

use App\Mail\PaperUpdateMail;
use App\Models\Article;
use App\Models\Author;
use App\Models\Certificate;
use App\Models\Maqola;
use App\Models\Paper;
use App\Services\Telegram\TelegramApi;
use App\Support\Locale;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Everything that happens to a manuscript, whether it came from the website or the bot. */
class PaperService
{
    public function __construct(
        private FileVault $vault,
        private CertificateService $certificates,
        private TelegramApi $telegram,
    ) {
    }

    /**
     * @param  array  $data  title, authors, affiliation, email, phone, abstract, keywords, locale, source, telegram_chat_id, telegram_username
     * @param  array|null  $telegramFile  ['file_id' => ..., 'file_name' => ..., 'file_size' => ...] for bot uploads
     */
    public function submit(array $data, ?UploadedFile $upload = null, ?array $telegramFile = null): Paper
    {
        $paper = new Paper(collect($data)->only((new Paper)->getFillable())->all());
        $paper->status = Paper::SUBMITTED;
        $paper->locale = Locale::normalize($paper->locale);
        $paper->save();

        $caption = $this->storageCaption($paper);

        if ($upload) {
            $stored = $this->vault->storeUpload($upload, $caption, 'local', 'papers');
            $paper->fill([
                'file_name' => $this->vault->safeName($upload->getClientOriginalName(), $upload->getClientOriginalExtension()),
                'file_size' => $upload->getSize(),
                'file_id' => $stored['file_id'],
                'file_path' => $stored['path'],
                'storage_message_id' => $stored['message_id'],
            ]);
        } elseif ($telegramFile) {
            $stored = $this->vault->storeTelegramFile($telegramFile['file_id'], $caption);
            $paper->fill([
                'file_name' => $telegramFile['file_name'] ?? 'paper.pdf',
                'file_size' => $telegramFile['file_size'] ?? null,
                'file_id' => $stored['file_id'],
                'storage_message_id' => $stored['message_id'],
            ]);
        }
        $paper->save();

        // Until the database update has created the check tables, fall back to the instant certificate.
        $checkReady = \App\Support\SchemaState::originalityReady();
        $checkFirst = $checkReady && config('journal.certificate_after_check') && config('journal.check_on_submit', true);
        if (Paper::certificateNeedsPayment()) {
            // No certificate at submission: the only one is sent after acceptance and payment.
            $this->notifyAuthor($paper, 'received_pay');
        } elseif ($checkFirst) {
            // The certificate follows once the originality check finds no authorship conflict.
            $this->notifyAuthor($paper, 'received');
        } else {
            $this->notifyAuthor($paper, 'submitted', $this->certificates->forSubmission($paper));
        }
        $this->notifyAdmins($paper);

        // Originality check runs after the author has their answer (it can take a minute).
        if ($checkReady && config('journal.check_on_submit', true)) {
            $run = fn () => app(\App\Services\Originality\OriginalityService::class)->run($paper->fresh());
            app()->runningInConsole() ? $run() : dispatch($run)->afterResponse();
        }

        return $paper;
    }

    public function markUnderReview(Paper $paper): Paper
    {
        if ($paper->status === Paper::SUBMITTED) {
            $paper->update(['status' => Paper::UNDER_REVIEW, 'reviewed_at' => now()]);
            $this->notifyAuthor($paper, 'under_review');
        }

        return $paper;
    }

    /**
     * Publish the paper as an article in the chosen issue and send the author its link.
     * The article PDF is the editor's upload if given, otherwise the submitted file, but only
     * when that file really is a PDF (DOC/DOCX manuscripts are never published as the PDF).
     */
    public function accept(Paper $paper, Maqola $issue, ?string $note = null, array $overrides = [], ?UploadedFile $pdf = null): Article
    {
        if ($paper->article) {
            return $paper->article;
        }

        $file = ['pdf_file_id' => null, 'pdf' => null];
        $caption = '📄 '.e($overrides['title'] ?? $paper->title);
        if (! $pdf && $paper->hasFile() && ! $this->paperIsPdf($paper) && ($converted = $this->convertedPdf($paper))) {
            // Word manuscript: publish the automatic PDF conversion.
            $pdf = new UploadedFile($converted, Str::slug(Str::limit($paper->title, 60, '')).'.pdf', 'application/pdf', null, true);
        }
        if ($pdf) {
            $stored = $this->vault->storeUpload($pdf, $caption, 'public', 'pdfs');
            $file = ['pdf_file_id' => $stored['file_id'], 'pdf' => $stored['path']];
        } elseif ($this->paperIsPdf($paper)) {
            $file = $paper->file_id
                ? ['pdf_file_id' => $paper->file_id, 'pdf' => null]
                : ['pdf_file_id' => null, 'pdf' => $this->copyLocalFileToPublic($paper)];
        }

        $article = DB::transaction(function () use ($paper, $issue, $note, $overrides, $file) {
            $article = Article::create([
                'volume' => (string) $issue->id,
                'title' => $overrides['title'] ?? $paper->title,
                'author' => $overrides['authors'] ?? $paper->authors,
                'keywords' => $overrides['keywords'] ?? ($paper->keywords ?? ''),
                'abstract' => $overrides['abstract'] ?? ($paper->abstract ?? ''),
            ] + $file);

            foreach ($article->authorList() as $name) {
                Author::firstOrCreate(['name' => $name]);
            }

            $paper->update([
                'status' => Paper::ACCEPTED,
                'issue_id' => $issue->id,
                'article_id' => $article->id,
                'admin_note' => $note ?: $paper->admin_note,
                'reviewed_at' => $paper->reviewed_at ?? now(),
                'published_at' => now(),
            ]);

            return $article;
        });

        $paper = $paper->fresh();
        if ($paper->certificateAllowed()) {
            $this->notifyAuthor($paper, 'accepted', $this->certificates->forArticle($article, $paper->locale, $paper));
        } else {
            // Link now; the certificate follows when the administrator confirms the payment.
            $this->notifyAuthor($paper, 'accepted_pay');
        }

        return $article;
    }

    /** The administrator confirmed the author's payment: record it and, if published, send the certificate. */
    public function confirmPayment(Paper $paper, string $by): ?Certificate
    {
        if (! $paper->isPaid()) {
            $paper->update(['paid_at' => now(), 'paid_by' => $by]);
        }
        $paper = $paper->fresh(['article']);
        if (! $paper->article) {
            return null; // the certificate goes out on acceptance
        }
        $certificate = $this->certificates->forArticle($paper->article, $paper->locale, $paper);
        $this->notifyAuthor($paper, 'paid', $certificate);

        return $certificate;
    }


    public function reject(Paper $paper, ?string $note = null): Paper
    {
        $paper->update(['status' => Paper::REJECTED, 'admin_note' => $note, 'reviewed_at' => now()]);
        $this->notifyAuthor($paper, 'rejected');

        return $paper;
    }

    public function articleUrl(Article $article, string $locale): string
    {
        return Locale::route('maqola', $article->id, $locale);
    }

    /** Message the author on every channel we know for them. Failures are logged, never thrown. */
    public function notifyAuthor(Paper $paper, string $event, ?Certificate $certificate = null): void
    {
        $locale = $paper->locale ?: 'uz';
        $pdf = null;
        if ($certificate) {
            try {
                $pdf = $this->certificates->pdf($certificate);
            } catch (Throwable $e) {
                Log::error('Certificate rendering failed', ['paper' => $paper->code, 'error' => $e->getMessage()]);
            }
        }

        if ($paper->telegram_chat_id && $this->telegram->enabled()) {
            try {
                $text = $this->telegramText($paper, $event, $locale);
                $markup = in_array($event, self::CHANNEL_INVITE_EVENTS, true) ? $this->channelMarkup($locale) : null;
                if ($pdf) {
                    $this->telegram->sendDocumentContents($paper->telegram_chat_id, $pdf, $certificate->fileName(), $text, $markup);
                } else {
                    $this->telegram->sendMessage($paper->telegram_chat_id, $text, $markup);
                }
            } catch (Throwable $e) {
                Log::warning('Telegram author notification failed', ['paper' => $paper->code, 'error' => $e->getMessage()]);
            }
        }

        if ($paper->email) {
            try {
                Mail::to($paper->email)->locale($locale)->send(new PaperUpdateMail($paper, $event, $certificate, $pdf));
            } catch (Throwable $e) {
                Log::warning('E-mail author notification failed', ['paper' => $paper->code, 'error' => $e->getMessage()]);
            }
        }
    }

    /** Author messages that carry the "subscribe to our channel" button. */
    public const CHANNEL_INVITE_EVENTS = ['submitted', 'received', 'received_pay', 'accepted', 'accepted_pay', 'paid'];

    /** Inline button that opens the journal's Telegram channel, or null when no channel is set. */
    public function channelMarkup(string $locale): ?array
    {
        $url = config('journal.telegram_channel');

        return $url ? ['inline_keyboard' => [[
            ['text' => '📢 '.__('Subscribe to our channel', [], $locale), 'url' => $url],
        ]]] : null;
    }

    /** "@emrojournal" from the channel URL. */
    public static function channelHandle(): ?string
    {
        $url = config('journal.telegram_channel');

        return $url ? '@'.basename((string) parse_url($url, PHP_URL_PATH)) : null;
    }

    public function sendCertificateToChat(Certificate $certificate, $chatId, ?string $caption = null): void
    {
        $this->telegram->sendDocumentContents($chatId, $this->certificates->pdf($certificate), $certificate->fileName(), $caption);
    }

    public function notifyAdmins(Paper $paper): void
    {
        if (! $this->telegram->enabled()) {
            return;
        }

        $caption = "🆕 <b>".e(__('New submission', [], config('journal.admin_locale')))."</b>\n\n".$this->storageCaption($paper)
            ."\n\n🔗 ".route('admin.papers.show', $paper);
        $markup = $this->adminKeyboard($paper);
        $storageChat = (string) config('services.telegram.storage_chat_id');

        foreach (config('services.telegram.admin_chat_ids', []) as $chatId) {
            try {
                if ($storageChat !== '' && (string) $chatId === $storageChat && $paper->storage_message_id) {
                    $this->telegram->call('editMessageReplyMarkup', [
                        'chat_id' => $chatId, 'message_id' => $paper->storage_message_id, 'reply_markup' => $markup,
                    ]);
                } elseif ($paper->file_id) {
                    $this->telegram->sendDocumentById($chatId, $paper->file_id, $caption, $markup);
                } else {
                    $this->telegram->sendMessage($chatId, $caption, $markup);
                }
            } catch (Throwable $e) {
                Log::warning('Admin notification failed', ['chat' => $chatId, 'error' => $e->getMessage()]);
            }
        }
    }

    public function adminKeyboard(Paper $paper): array
    {
        $locale = config('journal.admin_locale');

        return ['inline_keyboard' => [[
            ['text' => '🔍 '.__('Review', [], $locale), 'callback_data' => 'adm:review:'.$paper->id],
            ['text' => '✅ '.__('Accept', [], $locale), 'callback_data' => 'adm:accept:'.$paper->id],
            ['text' => '❌ '.__('Reject', [], $locale), 'callback_data' => 'adm:reject:'.$paper->id],
        ]]];
    }

    public function storageCaption(Paper $paper): string
    {
        return '📄 <b>'.e(Str::limit($paper->title, 300))."</b>\n"
            .'👥 '.e(Str::limit($paper->authors, 200))."\n"
            .($paper->email ? '✉️ '.e($paper->email)."\n" : '')
            .($paper->telegram_username ? '💬 @'.e($paper->telegram_username)."\n" : '')
            .'🏷 '.$paper->code.' · '.$paper->source;
    }

    private function telegramText(Paper $paper, string $event, string $locale): string
    {
        $t = fn (string $key, array $r = []) => e(__($key, $r, $locale));
        $title = '«'.e(Str::limit($paper->title, 200)).'»';

        return match ($event) {
            'submitted' => '✅ <b>'.$t('Your paper has been received')."</b>\n\n".$title."\n"
                .$t('Tracking code').': <code>'.$paper->code."</code>\n\n"
                .$t('Your submission certificate with a QR code is attached.')."\n"
                .route('papers.show', $paper),
            'received' => '✅ <b>'.$t('Your paper has been received')."</b>\n\n".$title."\n"
                .$t('Tracking code').': <code>'.$paper->code."</code>\n\n"
                .$t('Your certificate with a QR code will follow after an automatic originality check (usually within a few minutes).')."\n"
                .route('papers.show', $paper),
            'received_pay' => '✅ <b>'.$t('Your paper has been received')."</b>\n\n".$title."\n"
                .$t('Tracking code').': <code>'.$paper->code."</code>\n\n"
                .($paper->paymentRequired()
                    ? '💳 '.$t('Publication fee').': '.e(\App\Support\JournalProfile::get()['payment_fee'] ?? '')."\n"
                        .e(\App\Support\JournalProfile::get()['payment_info'] ?? '')."\n\n"
                        .$t('The publication certificate is sent after the paper is published and the payment is confirmed.')
                    : '🎁 '.$t('Publication in this issue is free.').' '.$t('The publication certificate is sent when the paper is published.'))."\n"
                .route('papers.show', $paper),
            'accepted_pay' => '🎉 <b>'.$t('Your paper has been accepted and published!')."</b>\n\n".$title."\n\n"
                .$t('Permanent link').': '.$this->articleUrl($paper->article, $locale)."\n\n"
                .'💳 '.$t('Publication fee').': '.e(\App\Support\JournalProfile::get()['payment_fee'] ?? '')."\n"
                .e(\App\Support\JournalProfile::get()['payment_info'] ?? '')."\n\n"
                .$t('Your publication certificate will be sent as soon as the payment is confirmed.'),
            'paid' => '✅ <b>'.$t('Payment confirmed')."</b>\n\n".$title."\n\n"
                .($paper->article ? $t('Permanent link').': '.$this->articleUrl($paper->article, $locale)."\n\n" : '')
                .$t('Your publication certificate is attached.'),
            'held' => '⏸ '.$t('Your paper needs an additional check by the editors before the certificate is issued. We will notify you.')."\n\n".$title,
            'under_review' => '🔍 '.$t('Your paper is now under review.')."\n\n".$title,
            'accepted' => '🎉 <b>'.$t('Your paper has been accepted and published!')."</b>\n\n".$title."\n\n"
                .$t('Permanent link').': '.$this->articleUrl($paper->article, $locale)."\n\n"
                .$t('Your publication certificate is attached.'),
            'rejected' => '❌ '.$t('Unfortunately, your paper was not accepted.')."\n\n".$title
                .($paper->admin_note ? "\n\n".$t('Editor comment').': '.e($paper->admin_note) : ''),
            default => $title,
        };
    }

    /**
     * PDF made from a Word manuscript, cached so the preview an editor checks is exactly what gets published.
     */
    public function convertedPdf(Paper $paper): ?string
    {
        $source = $this->manuscriptPath($paper);
        if (! $source || ! in_array(FileVault::detectExtension($source), ['doc', 'docx'], true)) {
            return null;
        }
        // "-t3": journal template (12 pt, 1.25, 2 cm, footnotes in place); older conversions are made again.
        $target = storage_path('app/convert/paper-'.$paper->id.'-'.md5_file($source).'-t4.pdf');
        if (is_file($target)) {
            return $target;
        }

        return app(WordToPdf::class)->convert($source, $target) ? $target : null;
    }

    /** Local path of the manuscript, downloading it from Telegram if that is where it lives. */
    public function manuscriptPath(Paper $paper): ?string
    {
        if ($local = $this->vault->localPath($paper->file_path, 'local')) {
            return $local;
        }
        if (! $paper->file_id) {
            return null;
        }
        $ext = strtolower(pathinfo((string) $paper->file_name, PATHINFO_EXTENSION)) ?: 'bin';
        $path = storage_path('app/convert/src-'.md5($paper->file_id).'.'.$ext);
        if (! is_file($path)) {
            try {
                $contents = $this->vault->contents($paper->file_id, null);
            } catch (Throwable $e) {
                return null;
            }
            if (! $contents) {
                return null;
            }
            \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, $contents);
        }

        return $path;
    }

    /** Is the submitted manuscript a PDF (checked by content for local files, by name for Telegram ones)? */
    public function paperIsPdf(Paper $paper): bool
    {
        $local = $this->vault->localPath($paper->file_path, 'local');
        if ($local) {
            return FileVault::detectExtension($local) === 'pdf';
        }

        return $paper->file_id !== null && strtolower(pathinfo((string) $paper->file_name, PATHINFO_EXTENSION)) === 'pdf';
    }

    private function copyLocalFileToPublic(Paper $paper): ?string
    {
        $local = $this->vault->localPath($paper->file_path, 'local');
        if (! $local) {
            return null;
        }
        $target = 'pdfs/'.Str::random(32).'.'.pathinfo($local, PATHINFO_EXTENSION);
        Storage::disk('public')->put($target, file_get_contents($local));

        return $target;
    }
}
