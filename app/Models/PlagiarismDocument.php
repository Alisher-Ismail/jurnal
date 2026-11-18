<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlagiarismDocument extends Model
{
    protected $fillable = ['type', 'ref_id', 'title', 'authors', 'text_gz', 'source_hash', 'words', 'fingerprints', 'error', 'indexed_at'];

    protected $hidden = ['text_gz'];

    public function setText(string $text): void
    {
        $this->text_gz = base64_encode(gzcompress($text, 6));
    }

    public function text(): string
    {
        return $this->text_gz ? (string) @gzuncompress(base64_decode($this->text_gz)) : '';
    }

    protected $casts = ['indexed_at' => 'datetime'];
}
