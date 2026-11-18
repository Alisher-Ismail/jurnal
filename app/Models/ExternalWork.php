<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A published work of Uzbek researchers, harvested from OpenAlex for the originality check. */
class ExternalWork extends Model
{
    protected $fillable = ['openalex_id', 'doi', 'title', 'authors', 'year', 'journal', 'url', 'pdf_url'];

    public function link(): ?string
    {
        return $this->url ?: ($this->doi ? 'https://doi.org/'.preg_replace('#^https?://doi\.org/#', '', $this->doi) : null)
            ?: 'https://openalex.org/'.$this->openalex_id;
    }
}
