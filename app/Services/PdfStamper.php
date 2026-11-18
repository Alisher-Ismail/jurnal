<?php

namespace App\Services;

use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\Tfpdf\Fpdi;

/**
 * Adds the journal's layout to an existing PDF. Articles get a first page in the style of
 * international journals (masthead, article type, DOI, dates, citation, licence) and a running
 * header/footer on the following pages; full issues get the running header/footer only.
 * The original file is never modified; stamped copies are cached in storage/app/stamped.
 */
class PdfStamper
{
    /** Bump to regenerate every cached stamped file after changing the layout. */
    private const VERSION = 6;

    private const NAVY = [20, 34, 63];

    private const GOLD = [184, 150, 82];

    private const BLUE = [31, 69, 196];

    private const GREY = [95, 103, 121];

    /** Very large proceedings are served as they are (memory/time on shared hosting). */
    public const MAX_BYTES = 60 * 1024 * 1024;

    /**
     * @param  array{header: string, footer: string, url: string}  $text
     * @return string|null path of the stamped copy, or null when the PDF cannot be stamped
     */
    public function stamp(string $sourcePath, array $text): ?string
    {
        if (! is_file($sourcePath) || filesize($sourcePath) > self::MAX_BYTES) {
            return null;
        }

        // Part of the cache key, so every PDF is re-stamped once the ISSN is entered or changed.
        $text['issn'] = $text['issn'] ?? \App\Support\JournalProfile::issnLabel();

        $key = md5(implode('|', [self::VERSION, $sourcePath, filesize($sourcePath), filemtime($sourcePath), json_encode($text)]));
        $target = storage_path('app/stamped/'.$key.'.pdf');
        if (is_file($target)) {
            return $target;
        }

        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        $this->prepareFonts();

        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCreator(config('journal.name'));
        $normalized = null;
        try {
            $pages = $pdf->setSourceFile($sourcePath);
        } catch (CrossReferenceException $e) {
            // PDF 1.5+ object streams (e.g. Word 2016): rewrite to a classic xref table and retry.
            $normalized = storage_path('app/stamped/'.$key.'.src.pdf');
            if (! is_dir(dirname($normalized))) {
                mkdir(dirname($normalized), 0775, true);
            }
            if (! app(PdfNormalizer::class)->normalize($sourcePath, $normalized)) {
                @unlink($normalized);

                return null;
            }
            $pdf = new Fpdi();
            $pdf->SetAutoPageBreak(false);
            $pdf->SetCreator(config('journal.name'));
            $pages = $pdf->setSourceFile($normalized);
        }
        $pdf->AddFont('DejaVu', '', 'DejaVuSans.ttf', true);
        $pdf->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.ttf', true);
        $pdf->AddFont('DejaVu', 'I', 'DejaVuSans-Oblique.ttf', true);
        $pdf->AddFont('DejaVuSerif', 'B', 'DejaVuSerif-Bold.ttf', true);

        $meta = $text['meta'] ?? null;
        // Document properties: search engines and reference managers read the title and authors from here.
        if ($meta) {
            if (! empty($meta['title'])) {
                $pdf->SetTitle($meta['title'], true);
            }
            if (! empty($meta['authors'])) {
                $pdf->SetAuthor($meta['authors'], true);
            }
            $pdf->SetSubject(trim(config('journal.name').' '.($meta['short'] ?? '').(! empty($meta['doi']) ? ' · DOI: '.$meta['doi'] : '')), true);
            if (! empty($meta['keywords'])) {
                $pdf->SetKeywords($meta['keywords'], true);
            }
        }
        for ($i = 1; $i <= $pages; $i++) {
            $template = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            // Portrait article pages: journal-style first page. Anything unusual keeps the plain stamp.
            if ($i === 1 && $meta && $size['height'] > $size['width']) {
                $this->firstPage($pdf, $template, $size['width'], $size['height'], $text, $meta, $pages);
            } else {
                $pdf->useTemplate($template);
                $this->decorate($pdf, $size['width'], $size['height'], $text, $i, $pages);
            }
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        $tmp = $target.'.'.getmypid().'.tmp';
        $pdf->Output('F', $tmp);
        rename($tmp, $target);
        SpaceSaver::limitCache();
        if ($normalized) {
            @unlink($normalized);
        }

        return $target;
    }

    /**
     * Article first page: masthead, article-type strip and metadata above the author's page,
     * dates, citation and licence below it. The author's page is scaled down to make room;
     * its empty top and bottom margins are trimmed so the text stays as large as possible.
     */
    private function firstPage(Fpdi $pdf, string $template, float $w, float $h, array $text, array $meta, int $pages): void
    {
        $m = 14;                       // side margin of the journal frame
        $top = 47.5;                   // masthead height
        $bottom = 31;                  // information box height
        $cropTop = 12;                 // empty top margin of a typical manuscript page
        $cropBottom = 10;
        $s = min(1, ($h - $top - $bottom) / ($h - $cropTop - $cropBottom));
        $pdf->useTemplate($template, ($w - $w * $s) / 2, $top - $cropTop * $s, $w * $s);

        // Cover whatever the manuscript had in its margins (e.g. a Word header) before drawing.
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(0, 0, $w, $top, 'F');
        $pdf->Rect(0, $h - $bottom, $w, $bottom, 'F');

        // Top line: "Emro Science and Education 2(3) (2026)" and the homepage (ISSN when set).
        $pdf->SetTextColor(...self::GREY);
        $pdf->SetFont('DejaVu', '', 6.8);
        $pdf->SetXY($m, 5.2);
        $pdf->Cell(110, 3.5, trim(config('journal.name').' '.($meta['short'] ?? '')), 0, 0, 'L', false, $text['url']);
        $right = trim((($text['issn'] ?? '') ? $text['issn'].' · ' : '').'journal.emro.uz');
        $pdf->SetXY($w - $m - 80, 5.2);
        $pdf->Cell(80, 3.5, $right, 0, 0, 'R', false, $text['url']);

        // Masthead box: logo, journal name, homepage, licence badge.
        $boxY = 10;
        $boxH = 22;
        $pdf->SetFillColor(243, 245, 250);
        $pdf->SetDrawColor(214, 220, 232);
        $pdf->SetLineWidth(0.25);
        $pdf->Rect($m, $boxY, $w - 2 * $m, $boxH, 'DF');
        $pdf->SetFillColor(...self::GOLD);
        $pdf->Rect($m, $boxY, 1.4, $boxH, 'F');
        $logo = resource_path('pdf/logo-mark.png');
        $textX = $m + 6;
        if (is_file($logo)) {
            $pdf->Image($logo, $m + 5, $boxY + 3, 0, 16, 'PNG', $text['url']);
            $textX = $m + 25;
        }
        // The journal name is a picture, not text: Google Scholar takes the largest text on the first
        // page as the paper's title, so the masthead must not out-size the article's own title.
        $nameImage = resource_path('pdf/masthead-name.png');
        if (is_file($nameImage)) {
            $pdf->Image($nameImage, $textX, $boxY + 4.3, 0, 5.4, 'PNG', $text['url']);
        } else {
            $pdf->SetTextColor(...self::NAVY);
            $pdf->SetFont('DejaVu', 'B', 9);
            $pdf->SetXY($textX, $boxY + 4.6);
            $pdf->Cell(120, 4, config('journal.name'), 0, 0, 'L', false, $text['url']);
        }
        $pdf->SetTextColor(...self::GREY);
        $pdf->SetFont('DejaVu', '', 7.6);
        $pdf->SetXY($textX, $boxY + 11.2);
        $pdf->Cell(120, 3.6, 'Open-access scientific journal');
        $pdf->SetXY($textX, $boxY + 15.2);
        $pdf->Cell(26, 3.6, 'Journal homepage: ');
        $pdf->SetTextColor(...self::BLUE);
        $pdf->Cell(60, 3.6, 'https://journal.emro.uz', 0, 0, 'L', false, $text['url']);

        // "Open Access" badge on the right.
        $bw = 27;
        $bx = $w - $m - $bw - 5;
        $pdf->SetFillColor(...self::NAVY);
        $pdf->Rect($bx, $boxY + 7.4, $bw, 7.2, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('DejaVu', 'B', 8.2);
        $pdf->SetXY($bx, $boxY + 9.2);
        $pdf->Cell($bw, 3.6, 'OPEN ACCESS', 0, 0, 'C');

        // Article type strip.
        $stripY = $boxY + $boxH + 2.2;
        $pdf->SetFillColor(...self::NAVY);
        $pdf->Rect($m, $stripY, $w - 2 * $m, 5.6, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('DejaVu', 'B', 7.4);
        $pdf->SetXY($m + 3, $stripY + 1.1);
        $pdf->Cell(80, 3.5, mb_strtoupper($meta['type'] ?? 'Research article'));
        $pdf->SetXY($w - $m - 83, $stripY + 1.1);
        $pdf->Cell(80, 3.5, 'OPEN ACCESS', 0, 0, 'R');

        // Issue · published · DOI.
        $lineY = $stripY + 7.6;
        $pdf->SetFont('DejaVu', '', 7.4);
        $pdf->SetXY($m, $lineY);
        $parts = array_filter([
            $meta['issue'] ?? null,
            ! empty($meta['published']) ? 'Published: '.$meta['published'] : null,
        ]);
        $info = implode('   |   ', $parts);
        $pdf->SetTextColor(...self::GREY);
        $doiText = ! empty($meta['doi']) ? 'DOI: '.$meta['doi'] : null;
        $doiWidth = $doiText ? $pdf->GetStringWidth($doiText) + 2 : 0;
        $pdf->Cell($w - 2 * $m - $doiWidth - 4, 3.6, $this->fit($pdf, $info, $w - 2 * $m - $doiWidth - 4));
        if ($doiText) {
            $pdf->SetTextColor(...self::BLUE);
            $pdf->SetXY($w - $m - $doiWidth, $lineY);
            $pdf->Cell($doiWidth, 3.6, $doiText, 0, 0, 'R', false, $meta['doi_url'] ?? $text['url']);
        }
        $pdf->SetDrawColor(...self::GOLD);
        $pdf->SetLineWidth(0.35);
        $pdf->Line($m, $top - 1.2, $w - $m, $top - 1.2);

        // Information box at the foot of the page: dates, citation, licence.
        $y = $h - $bottom + 1.2;
        $pdf->SetDrawColor(...self::GOLD);
        $pdf->SetLineWidth(0.35);
        $pdf->Line($m, $y, $w - $m, $y);
        $pdf->SetLineWidth(0.2);
        $y += 1.6;
        $dates = array_filter([
            ! empty($meta['received']) ? 'Received: '.$meta['received'] : null,
            ! empty($meta['accepted']) ? 'Accepted: '.$meta['accepted'] : null,
            ! empty($meta['published']) ? 'Published: '.$meta['published'] : null,
        ]);
        $pdf->SetTextColor(...self::GREY);
        $pdf->SetFont('DejaVu', '', 6.8);
        if ($dates) {
            $pdf->SetXY($m, $y);
            $pdf->Cell($w - 2 * $m, 3.2, implode('   ·   ', $dates));
            $y += 4;
        }
        // Write() wraps inside the page margins, so the bold label and the reference share one paragraph.
        $pdf->SetLeftMargin($m);
        $pdf->SetRightMargin($m);
        if (! empty($meta['citation'])) {
            $pdf->SetXY($m, $y);
            $pdf->SetFont('DejaVu', 'B', 6.8);
            $pdf->SetTextColor(...self::NAVY);
            $label = 'Cite this article: ';
            $pdf->Write(3.2, $label);
            $pdf->SetFont('DejaVu', '', 6.8);
            $pdf->SetTextColor(...self::GREY);
            $pdf->Write(3.2, $this->clampLines($pdf, $meta['citation'], $w - 2 * $m, 3) , $meta['doi_url'] ?? '');
            $y = $pdf->GetY() + 4;
        }
        $pdf->SetXY($m, $y);
        $pdf->SetFont('DejaVu', 'I', 6.4);
        $pdf->SetTextColor(...self::GREY);
        $pdf->MultiCell($w - 2 * $m, 3, '© '.($meta['year'] ?? date('Y')).' The Author(s). Published by '.config('journal.name')
            .'. This is an open access article.', 0, 'L');

        // Page footer, as on the other pages.
        $pdf->SetDrawColor(210, 214, 222);
        $pdf->Line($m, $h - 7.6, $w - $m, $h - 7.6);
        $pdf->SetFont('DejaVu', '', 7);
        $counter = '1 / '.$pages;
        $counterWidth = $pdf->GetStringWidth($counter);
        $pdf->SetXY($m, $h - 6.8);
        $pdf->Cell($w - 2 * $m - $counterWidth - 4, 3.5, $this->fit($pdf, $text['footer'], $w - 2 * $m - $counterWidth - 4), 0, 0, 'L', false, $text['url']);
        $pdf->SetXY($w - $m - $counterWidth, $h - 6.8);
        $pdf->Cell($counterWidth, 3.5, $counter, 0, 0, 'R');
    }

    /** Keep a wrapped text within $lines lines of the given width (cuts with an ellipsis). */
    private function clampLines(Fpdi $pdf, string $text, float $width, int $lines): string
    {
        $limit = $width * $lines * 0.97;
        if ($pdf->GetStringWidth($text) <= $limit) {
            return $text;
        }
        while (mb_strlen($text) > 1 && $pdf->GetStringWidth($text.'…') > $limit) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    private function decorate(Fpdi $pdf, float $w, float $h, array $text, int $page, int $pages): void
    {
        $article = ! empty($text['meta']);
        // Articles get a larger running header; full issues (often scans with tight margins) keep it small.
        $margin = $article ? 12 : 10;
        $logoH = $article ? 6 : 4.4;
        $font = $article ? 8.8 : 7;
        $textY = $article ? 3.4 : 3.2;
        $lineY = $article ? 10 : 7.4;
        $footFont = $article ? 7.6 : 7;
        $footLineY = $h - ($article ? 8.6 : 7.6);
        if ($article) {
            // Keep the running header and footer clear of anything in the manuscript's margins.
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect(0, 0, $w, $lineY + 0.8, 'F');
            $pdf->Rect(0, $footLineY - 0.6, $w, $h - $footLineY + 0.6, 'F');
        }
        $pdf->SetTextColor(...self::GREY);
        $pdf->SetDrawColor(210, 214, 222);
        $pdf->SetLineWidth(0.2);

        // Header: logo, journal name (bold) · issue
        $logo = resource_path('pdf/logo.png');
        $textX = $margin;
        if (is_file($logo)) {
            $pdf->Image($logo, $margin, $textY - ($logoH - 4.2) / 2 - 0.4, 0, $logoH, 'PNG', $text['url']);
            $textX = $margin + $logoH + 1.6;
        }
        $pdf->SetFont('DejaVu', 'B', $font);
        if ($article) {
            $pdf->SetTextColor(...self::NAVY);
        }
        $journal = config('journal.name');
        $pdf->SetXY($textX, $textY);
        $pdf->Cell($pdf->GetStringWidth($journal) + 1, 4.2, $journal, 0, 0, 'L', false, $text['url']);
        $pdf->SetTextColor(...self::GREY);
        $pdf->SetFont('DejaVu', '', $font);
        $site = ! empty($text['meta']['doi'])
            ? 'DOI: '.$text['meta']['doi']
            : (($text['issn'] ?? null) ? $text['issn'].' · journal.emro.uz' : 'journal.emro.uz');
        $siteLink = $text['meta']['doi_url'] ?? $text['url'];
        $siteWidth = $pdf->GetStringWidth($site);
        $room = $w - 2 * $margin - ($textX - $margin) - $pdf->GetStringWidth($journal) - $siteWidth - 8;
        $pdf->Cell($room, 4.2, $this->fit($pdf, '· '.$text['header'], $room));
        if (! empty($text['meta']['doi'])) {
            $pdf->SetTextColor(...self::BLUE);
        }
        $pdf->SetXY($w - $margin - $siteWidth, $textY);
        $pdf->Cell($siteWidth, 4.2, $site, 0, 0, 'R', false, $siteLink);
        $pdf->SetTextColor(...self::GREY);
        if ($article) {
            $pdf->SetDrawColor(...self::GOLD);
            $pdf->SetLineWidth(0.3);
        }
        $pdf->Line($margin, $lineY, $w - $margin, $lineY);

        // Footer: publication info · permanent link · page
        $pdf->SetDrawColor(210, 214, 222);
        $pdf->SetLineWidth(0.2);
        $pdf->Line($margin, $footLineY, $w - $margin, $footLineY);
        $pdf->SetFont('DejaVu', '', $footFont);
        $counter = $page.' / '.$pages;
        $counterWidth = $pdf->GetStringWidth($counter);
        $pdf->SetXY($margin, $footLineY + 0.9);
        $pdf->Cell($w - 2 * $margin - $counterWidth - 4, 3.8, $this->fit($pdf, $text['footer'], $w - 2 * $margin - $counterWidth - 4), 0, 0, 'L', false, $text['url']);
        $pdf->SetXY($w - $margin - $counterWidth, $footLineY + 0.9);
        $pdf->Cell($counterWidth, 3.8, $counter, 0, 0, 'R');
    }

    /** Shorten text with an ellipsis so it fits the given width. */
    private function fit(Fpdi $pdf, string $text, float $width): string
    {
        if ($pdf->GetStringWidth($text) <= $width) {
            return $text;
        }
        while (mb_strlen($text) > 1 && $pdf->GetStringWidth($text.'…') > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    /** tFPDF reads fonts from FPDF_FONTPATH/unifont and caches metrics there, so it must be writable. */
    private function prepareFonts(): void
    {
        $dir = storage_path('app/pdf-fonts/unifont');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        foreach (['DejaVuSans.ttf', 'DejaVuSans-Bold.ttf', 'DejaVuSans-Oblique.ttf', 'DejaVuSerif-Bold.ttf'] as $font) {
            if (! is_file($dir.'/'.$font)) {
                copy(base_path('vendor/dompdf/dompdf/lib/fonts/'.$font), $dir.'/'.$font);
            }

            // tFPDF caches font metrics with the absolute path of the .ttf file. A cache made in
            // another folder (e.g. uploaded from a PC) points to a missing file and breaks every
            // stamp, so it is dropped here and rebuilt on first use.
            $cache = $dir.'/'.strtolower(pathinfo($font, PATHINFO_FILENAME));
            $mtx = $cache.'.mtx.php';
            if (is_file($mtx)) {
                try {
                    $ttffile = (static function (string $file) {
                        include $file;

                        return $ttffile ?? null;
                    })($mtx);
                } catch (\Throwable $e) {
                    $ttffile = null;
                }
                if (! is_string($ttffile) || ! is_file($ttffile) || realpath($ttffile) !== realpath($dir.'/'.$font)) {
                    foreach (['.mtx.php', '.cw.dat', '.cw127.php'] as $ext) {
                        @unlink($cache.$ext);
                    }
                }
            }
        }
        if (! defined('FPDF_FONTPATH')) {
            define('FPDF_FONTPATH', storage_path('app/pdf-fonts').'/');
        }
    }
}
