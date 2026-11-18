<?php

namespace App\Models\Concerns;

use App\Services\FileVault;

/** PDF of an issue or article: stored in Telegram (pdf_file_id) and/or locally (pdf). */
trait HasPdf
{
    private ?string $resolvedPdfUrl = null;
    private ?string $pdfResolvedFor = null;

    /** Something is recorded in the database (the file itself may still be missing). */
    public function hasFile(): bool
    {
        return filled($this->pdf_file_id) || filled($this->pdf);
    }

    /**
     * Download URL of the stamped copy (see FileController), or null when the file cannot be
     * found, so no broken PDF button is shown. Old /storage/pdfs/... links redirect there too.
     */
    public function pdfUrl(): ?string
    {
        // Memoized per file value, so a changed or refreshed record is looked up again.
        $for = $this->pdf.'|'.$this->pdf_file_id;
        if ($this->pdfResolvedFor === $for) {
            return $this->resolvedPdfUrl;
        }
        $this->pdfResolvedFor = $for;
        $this->resolvedPdfUrl = null;
        $route = $this->pdfRoute();

        if ($this->pdf_file_id) {
            return $this->resolvedPdfUrl = route($route, $this->id);
        }

        $local = app(FileVault::class)->localPath($this->pdf, 'public');
        if (! $local || FileVault::detectExtension($local) !== 'pdf') {
            return null;
        }

        return $this->resolvedPdfUrl = route($route, $this->id);
    }

    public function pdfMissing(): bool
    {
        return $this->hasFile() && $this->pdfUrl() === null;
    }

    /** Why there is no PDF link: 'missing' (file not on the server) or 'not_pdf' (e.g. a DOCX). */
    public function pdfProblem(): ?string
    {
        if (! $this->pdfMissing()) {
            return null;
        }
        $local = app(FileVault::class)->localPath($this->pdf, 'public');

        return $local ? 'not_pdf' : 'missing';
    }
}
