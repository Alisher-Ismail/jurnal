<?php

namespace App\Services;

use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\Tfpdf\Fpdi;

/**
 * Copies a page range of a PDF into a new file (used to publish the papers of a
 * proceedings book as separate articles). Pages are copied as they are, without stamping.
 */
class PdfSplitter
{
    /** Normalized copy of the last source, reused across calls in one request. */
    private array $readable = [];

    /** Page count of the source used in the last extract() call. */
    public int $lastTotalPages = 0;

    public function extract(string $source, int $from, int $to, string $target): int
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $pdf = $this->open();
        $pages = $this->lastTotalPages = $pdf->setSourceFile($this->readableSource($source));
        if ($from < 1 || $to < $from || $to > $pages) {
            throw new \InvalidArgumentException("Pages {$from}-{$to} are outside 1-{$pages}.");
        }

        for ($i = $from; $i <= $to; $i++) {
            $template = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        $tmp = $target.'.'.getmypid().'.tmp';
        $pdf->Output('F', $tmp);
        rename($tmp, $target);

        return $to - $from + 1;
    }

    private function open(): Fpdi
    {
        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCreator((string) config('journal.name'));

        return $pdf;
    }

    /**
     * FPDI's free parser cannot read PDF 1.5+ object streams (e.g. Word 2016 exports), so such
     * files are rewritten once with PdfNormalizer; the copy is kept next to the stamping cache.
     */
    private function readableSource(string $source): string
    {
        if (isset($this->readable[$source])) {
            return $this->readable[$source];
        }

        try {
            (new Fpdi())->setSourceFile($source);

            return $this->readable[$source] = $source;
        } catch (CrossReferenceException $e) {
            $copy = storage_path('app/stamped/split-'.md5($source.'|'.filesize($source).'|'.filemtime($source)).'.pdf');
            if (! is_file($copy)) {
                if (! is_dir(dirname($copy))) {
                    mkdir(dirname($copy), 0775, true);
                }
                if (! app(PdfNormalizer::class)->normalize($source, $copy)) {
                    @unlink($copy);
                    throw new \RuntimeException('This PDF cannot be read for splitting.');
                }
            }

            return $this->readable[$source] = $copy;
        }
    }
}
