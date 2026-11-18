<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\IOFactory;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Converts DOC/DOCX manuscripts to PDF.
 * 1) LibreOffice, when installed and the host allows running it (best fidelity);
 * 2) otherwise pure PHP: PHPWord reads the document, dompdf renders it
 *    (text, headings, lists, tables and images; complex layouts are simplified).
 */
class WordToPdf
{
    /** @return string|null the engine that produced $target, or null on failure */
    public function convert(string $source, string $target, ?string $engine = null): ?string
    {
        $engine = $engine ?? config('journal.word_converter', 'auto');
        $type = FileVault::detectExtension($source);
        if (! in_array($type, ['docx', 'doc'], true)) {
            return null;
        }
        File::ensureDirectoryExists(dirname($target));

        if ($engine !== 'phpword' && ($soffice = $this->libreOffice())) {
            try {
                if ($this->withLibreOffice($soffice, $source, $target, $type)) {
                    return 'libreoffice';
                }
            } catch (Throwable $e) {
                Log::warning('LibreOffice conversion failed', ['error' => $e->getMessage()]);
            }
        }

        if ($engine !== 'libreoffice') {
            try {
                if ($this->withPhpWord($source, $target, $type)) {
                    return 'phpword';
                }
            } catch (Throwable $e) {
                Log::warning('PHPWord conversion failed', ['error' => $e->getMessage()]);
            }
        }

        return null;
    }

    /** Path of a usable soffice binary, or null (also when the host disables process execution). */
    public function libreOffice(): ?string
    {
        if (! function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return null;
        }
        $candidates = array_filter([
            config('journal.soffice_path'),
            '/usr/bin/soffice', '/usr/bin/libreoffice', '/usr/local/bin/soffice', '/opt/libreoffice/program/soffice',
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
        ]);
        foreach (glob('/opt/libreoffice*/program/soffice') ?: [] as $found) {
            $candidates[] = $found;
        }
        foreach ($candidates as $path) {
            if (@is_file($path) && @is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function withLibreOffice(string $soffice, string $source, string $target, string $type): bool
    {
        $work = storage_path('app/convert/'.uniqid('lo', true));
        File::ensureDirectoryExists($work);
        $input = $work.'/document.'.$type;
        copy($source, $input);
        $profile = 'file:///'.ltrim(str_replace('\\', '/', storage_path('app/convert/lo-profile')), '/');

        $process = new Process([$soffice, '-env:UserInstallation='.$profile, '--headless', '--norestore', '--convert-to', 'pdf', '--outdir', $work, $input]);
        $process->setTimeout(180);
        $process->run();

        $output = $work.'/document.pdf';
        $ok = is_file($output) && FileVault::detectExtension($output) === 'pdf';
        if ($ok) {
            rename($output, $target);
        }
        File::deleteDirectory($work);

        return $ok;
    }

    private function withPhpWord(string $source, string $target, string $type): bool
    {
        @set_time_limit(180);
        $work = storage_path('app/convert/'.uniqid('pw', true));
        File::ensureDirectoryExists($work);

        try {
            if ($type === 'docx') {
                $prepared = app(DocxPreparer::class)->prepare($source, $work);
                $document = IOFactory::load($prepared['path'], 'Word2007');
                if ($prepared['font']) {
                    $document->setDefaultFontName($prepared['font']);
                }
                if ($prepared['size']) {
                    $document->setDefaultFontSize($prepared['size']);
                }
            } else {
                $document = IOFactory::load($source, 'MsDoc');
            }
            $html = IOFactory::createWriter($document, 'HTML')->getContent();
        } finally {
            File::deleteDirectory($work);
        }

        $html = $this->applyJournalTemplate($html);
        File::ensureDirectoryExists(storage_path('fonts'));

        $pdf = Pdf::loadHTML($html)->setPaper('a4')->setOption([
            'isFontSubsettingEnabled' => true,
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Serif',
        ]);
        file_put_contents($target, $pdf->output());

        return FileVault::detectExtension($target) === 'pdf';
    }

    /**
     * The journal's page format, whatever the author used: Times (Liberation Serif, which has the same
     * letter widths and covers Uzbek ʻ ў қ ғ ҳ), 12 pt, line spacing 1.25, 2 cm margins on every side of A4,
     * tables in 11 pt. Bold, italic, alignment (centred title and authors), lists, tables and images are kept.
     */
    private function applyJournalTemplate(string $html): string
    {
        // The author's fonts, sizes and line spacing give way to the template
        // (kept: 9.5 pt footnotes and the 14.5 pt title, both set by DocxPreparer).
        $html = preg_replace('/(?<![-\w])(font-family|line-height)\s*:\s*[^;"]*;?\s*/i', '', $html);
        $html = preg_replace('/(?<![-\w])font-size\s*:\s*+(?!9\.5pt|14\.5pt)[^;"]*;?\s*/i', '', $html);
        $html = preg_replace('/\sstyle="\s*"/', '', $html);

        $face = function (string $file, string $weight, string $style) {
            $path = str_replace('\\', '/', resource_path('fonts/'.$file));

            return "@font-face { font-family: 'Journal Serif'; font-weight: {$weight}; font-style: {$style}; src: url('{$path}') format('truetype'); }";
        };
        $fonts = is_file(resource_path('fonts/LiberationSerif-Regular.ttf'))
            ? $face('LiberationSerif-Regular.ttf', 'normal', 'normal').$face('LiberationSerif-Bold.ttf', 'bold', 'normal')
                .$face('LiberationSerif-Italic.ttf', 'normal', 'italic').$face('LiberationSerif-BoldItalic.ttf', 'bold', 'italic')
            : '';
        $family = $fonts ? "'Journal Serif', 'DejaVu Serif'" : "'DejaVu Serif'";

        return str_replace('</head>', '<style>'.$fonts.'
            @page { size: A4; margin: 2cm; }
            body { font-family: '.$family.'; font-size: 12pt; line-height: 1.25; }
            p { margin-top: 0; text-align: justify; }
            p[style*="justify"] { text-indent: 1.25cm; }
            h1, h2, h3, h4, h5, h6 { font-family: '.$family.'; font-size: 12pt; font-weight: bold; margin: 6pt 0 3pt; page-break-after: avoid; }
            table { border-collapse: collapse; margin: 6pt auto; font-size: 11pt; }
            td, th { border: 0.6pt solid #444; padding: 2pt 4pt; vertical-align: top; font-size: 11pt; line-height: 1.1; }
            td p, th p { margin: 0; }
            sup, sub, span[style*="vertical-align: super"], span[style*="vertical-align: sub"] { font-size: 65%; }
            img { max-width: 100%; }
        </style></head>', $html);
    }
}
