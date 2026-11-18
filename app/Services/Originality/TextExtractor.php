<?php

namespace App\Services\Originality;

use App\Services\FileVault;
use App\Services\PdfNormalizer;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/** Plain text of a PDF, DOCX or DOC file. */
class TextExtractor
{
    public function extract(string $path): string
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '768M');

        $text = match (FileVault::detectExtension($path)) {
            'pdf' => $this->pdf($path),
            'docx' => $this->docx($path),
            'doc' => $this->doc($path),
            default => '',
        };

        // PDFs often contain a few invalid UTF-8 bytes, which make every /u regex fail on the whole text.
        $text = mb_scrub($text, 'UTF-8');
        // Fonts without the Uzbek ʻ often turn it into "?" or "�" inside words (Farg?ona): treat as an apostrophe.
        $text = (string) preg_replace('/(?<=\p{L})[?\x{FFFD}](?=\p{L})/u', "'", $text);

        return (string) preg_replace('/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{FFFD}]/u', ' ', $text);
    }

    private function pdf(string $path): string
    {
        $config = new Config();
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(64 * 1024 * 1024);

        try {
            $document = (new Parser([], $config))->parseFile($path);
        } catch (Throwable $e) {
            // Some Word 2016+ PDFs only parse after rewriting their cross-reference streams.
            $normalized = storage_path('app/convert/'.uniqid('txt', true).'.pdf');
            File::ensureDirectoryExists(dirname($normalized));
            if (! app(PdfNormalizer::class)->normalize($path, $normalized)) {
                throw $e;
            }
            try {
                $document = (new Parser([], $config))->parseFile($normalized);
            } finally {
                @unlink($normalized);
            }
        }

        $text = '';
        foreach ($document->getPages() as $page) {
            try {
                $text .= $page->getText()."\n\n";
            } catch (Throwable $e) {
                // One unreadable page should not lose the rest of the document.
            }
        }

        return $text;
    }

    private function docx(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $xml = preg_replace(['#</w:p>#', '#<w:tab/>#', '#<w:br[^>]*/>#'], ["\n\n", ' ', "\n"], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function doc(string $path): string
    {
        $text = '';
        $collect = function ($element) use (&$collect, &$text) {
            if (method_exists($element, 'getElements')) {
                foreach ($element->getElements() as $child) {
                    $collect($child);
                }
                $text .= "\n";
            } elseif (method_exists($element, 'getText')) {
                $value = $element->getText();
                $text .= is_string($value) ? $value.' ' : '';
            }
        };
        foreach (IOFactory::load($path, 'MsDoc')->getSections() as $section) {
            $collect($section);
        }

        return $text;
    }
}
