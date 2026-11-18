<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A manuscript submitted through the website or the Telegram bot. */
class Paper extends Model
{
    public const SUBMITTED = 'submitted';
    public const UNDER_REVIEW = 'under_review';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';

    public const STATUSES = [self::SUBMITTED, self::UNDER_REVIEW, self::ACCEPTED, self::REJECTED];

    protected $fillable = [
        'code', 'title', 'authors', 'affiliation', 'email', 'phone', 'abstract', 'keywords', 'locale',
        'source', 'telegram_chat_id', 'telegram_username', 'file_name', 'file_size', 'file_id', 'file_path',
        'storage_message_id', 'status', 'issue_id', 'article_id', 'admin_note', 'reviewed_at', 'published_at', 'submitter_ip',
        'license_agreed_at', 'paid_at', 'paid_by', 'review_text', 'review_decision', 'review_by', 'review_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'published_at' => 'datetime',
        'license_agreed_at' => 'datetime',
        'paid_at' => 'datetime',
        'review_at' => 'datetime',
    ];

    /** Recommendations an editor can give with the review (taqriz). */
    public const REVIEW_DECISIONS = ['accept', 'minor', 'major', 'reject'];

    /** Certificates only after the administrator confirmed the payment (journal.certificate_after_payment). */
    public static function certificateNeedsPayment(): bool
    {
        return (bool) config('journal.certificate_after_payment', true);
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    /**
     * Publication is free in this paper's issue. Accepted papers follow their issue;
     * before acceptance, the issue now open for submissions decides.
     */
    public function isFree(): bool
    {
        $issue = $this->issue_id ? $this->issue : Maqola::current();

        return (bool) $issue?->is_free;
    }

    /** The author has to pay before the publication certificate is sent. */
    public function paymentRequired(): bool
    {
        return self::certificateNeedsPayment() && ! $this->isFree();
    }

    /** The publication certificate may be given out: published and, when payment is required, paid. */
    public function certificateAllowed(): bool
    {
        return $this->article_id && (! $this->paymentRequired() || $this->isPaid());
    }

    protected static function booted()
    {
        static::creating(function (Paper $paper) {
            if (! $paper->code) {
                do {
                    $code = 'ES-'.now()->format('y').'-'.strtoupper(Str::random(6));
                } while (static::where('code', $code)->exists());
                $paper->code = $code;
            }
        });
    }

    public function getRouteKeyName()
    {
        return 'code';
    }

    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    public function issue()
    {
        return $this->belongsTo(Maqola::class, 'issue_id');
    }

    public function check()
    {
        return $this->hasOne(PaperCheck::class);
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }

    public function submissionCertificate()
    {
        return $this->hasOne(Certificate::class)->where('type', Certificate::SUBMISSION);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::SUBMITTED, self::UNDER_REVIEW], true);
    }

    public function hasFile(): bool
    {
        return filled($this->file_id) || filled($this->file_path);
    }

    public function telegramStartLink(): ?string
    {
        $bot = config('services.telegram.username');

        return $bot ? 'https://t.me/'.ltrim($bot, '@').'?start=p_'.$this->code : null;
    }
}
