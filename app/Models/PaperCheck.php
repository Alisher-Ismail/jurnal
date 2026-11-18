<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Originality report of a submission: archive similarity, web matches and the AI-writing indicator. */
class PaperCheck extends Model
{
    protected $fillable = [
        'paper_id', 'status', 'words', 'similarity', 'sources', 'passages', 'web_checked', 'web_found',
        'web_matches', 'web_note', 'ai_score', 'ai_signals', 'error', 'checked_at',
        'conflict', 'certificate_held', 'external_service', 'external_similarity', 'external_ai',
        'external_report', 'external_note', 'external_by', 'external_at',
    ];

    protected $casts = [
        'sources' => 'array',
        'passages' => 'array',
        'web_matches' => 'array',
        'ai_signals' => 'array',
        'similarity' => 'float',
        'checked_at' => 'datetime',
        'conflict' => 'array',
        'certificate_held' => 'boolean',
        'external_similarity' => 'float',
        'external_ai' => 'float',
        'external_at' => 'datetime',
    ];

    public function paper()
    {
        return $this->belongsTo(Paper::class);
    }

    /** low | medium | high, for colouring badges. */
    public function similarityLevel(): string
    {
        $s = (float) $this->similarity;

        return $s >= 30 ? 'high' : ($s >= 15 ? 'medium' : 'low');
    }

    /** 'mismatch' (text of other authors), 'web' (largely found online), 'same_authors', or null. */
    public function conflictType(): ?string
    {
        return $this->conflict['type'] ?? null;
    }

    public function aiLevel(): string
    {
        $s = (int) $this->ai_score;

        return $s >= 65 ? 'high' : ($s >= 40 ? 'medium' : 'low');
    }
}
