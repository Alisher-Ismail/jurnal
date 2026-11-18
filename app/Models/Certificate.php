<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Certificate extends Model
{
    public const SUBMISSION = 'submission';
    public const PUBLICATION = 'publication';

    protected $fillable = ['code', 'type', 'paper_id', 'article_id', 'recipient', 'title', 'locale', 'issued_at'];

    protected $casts = ['issued_at' => 'datetime'];

    protected static function booted()
    {
        static::creating(function (Certificate $certificate) {
            $certificate->code = $certificate->code ?: strtoupper(Str::random(12));
            $certificate->issued_at = $certificate->issued_at ?: now();
        });
    }

    public function getRouteKeyName()
    {
        return 'code';
    }

    public function paper()
    {
        return $this->belongsTo(Paper::class);
    }

    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    public function verifyUrl(): string
    {
        return route('certificate.show', $this->code);
    }

    public function fileName(): string
    {
        return 'certificate-'.$this->code.'.pdf';
    }
}
