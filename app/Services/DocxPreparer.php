<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Fills two gaps of PHPWord before converting a DOCX to PDF:
 * - list markers (bullets and "1.", "a)" ... numbering, including style-based lists)
 *   are written into the text, because PHPWord drops them;
 * - the document's own default font and size are reported, because PHPWord assumes Arial 11.
 */
class DocxPreparer
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @return array{path: string, font: ?string, size: ?float} */
    public function prepare(string $source, string $workDir): array
    {
        $copy = $workDir.'/prepared.docx';
        copy($source, $copy);
        $zip = new ZipArchive();
        if ($zip->open($copy) !== true) {
            return ['path' => $source, 'font' => null, 'size' => null];
        }

        $styles = $this->load($zip->getFromName('word/styles.xml'));
        $numbering = $this->load($zip->getFromName('word/numbering.xml'));
        $document = $this->load($zip->getFromName('word/document.xml'));
        [$font, $size] = $styles ? $this->defaultFont($styles) : [null, null];

        if ($document) {
            $this->markTitle($document);
            $this->flattenMath($document);
            $this->inlineNotes($document, [
                'footnote' => $this->load($zip->getFromName('word/footnotes.xml')),
                'endnote' => $this->load($zip->getFromName('word/endnotes.xml')),
            ]);
            $this->writeListMarkers($document, $numbering ? $this->numberFormats($numbering) : [], $styles ? $this->styleNumbering($styles) : []);
            $zip->addFromString('word/document.xml', $document->saveXML());
        }
        $zip->close();

        return ['path' => $copy, 'font' => $font, 'size' => $size];
    }

    private function load(string|false $xml): ?DOMDocument
    {
        if (! $xml) {
            return null;
        }
        $dom = new DOMDocument();

        return @$dom->loadXML($xml, LIBXML_NONET) ? $dom : null;
    }

    private function xpath(DOMDocument $dom): DOMXPath
    {
        $x = new DOMXPath($dom);
        $x->registerNamespace('w', self::W);

        return $x;
    }

    /** @return array{0: ?string, 1: ?float} font name and size (pt) of the Normal style / document defaults */
    private function defaultFont(DOMDocument $styles): array
    {
        $x = $this->xpath($styles);
        $font = $size = null;
        foreach (['//w:docDefaults/w:rPrDefault/w:rPr', "//w:style[@w:type='paragraph' and @w:default='1']/w:rPr"] as $path) {
            foreach ($x->query($path) as $rPr) {
                $fonts = $x->query('w:rFonts', $rPr)->item(0);
                if ($fonts instanceof DOMElement && $fonts->getAttributeNS(self::W, 'ascii')) {
                    $font = $fonts->getAttributeNS(self::W, 'ascii');
                }
                $sz = $x->query('w:sz', $rPr)->item(0);
                if ($sz instanceof DOMElement && is_numeric($sz->getAttributeNS(self::W, 'val'))) {
                    $size = (float) $sz->getAttributeNS(self::W, 'val') / 2;
                }
            }
        }

        return [$font, $size];
    }

    /** numId => ilvl => [format, text, start] */
    private function numberFormats(DOMDocument $numbering): array
    {
        $x = $this->xpath($numbering);
        $abstract = [];
        foreach ($x->query('//w:abstractNum') as $a) {
            $id = $a->getAttributeNS(self::W, 'abstractNumId');
            foreach ($x->query('w:lvl', $a) as $lvl) {
                $abstract[$id][(int) $lvl->getAttributeNS(self::W, 'ilvl')] = [
                    $this->val($x, 'w:numFmt', $lvl) ?: 'decimal',
                    $this->val($x, 'w:lvlText', $lvl) ?? '%1.',
                    (int) ($this->val($x, 'w:start', $lvl) ?: 1),
                ];
            }
        }
        $map = [];
        foreach ($x->query('//w:num') as $num) {
            $absId = $this->val($x, 'w:abstractNumId', $num);
            if ($absId !== null && isset($abstract[$absId])) {
                $map[$num->getAttributeNS(self::W, 'numId')] = $abstract[$absId];
            }
        }

        return $map;
    }

    /** styleId => [numId, ilvl] for list styles such as "List Bullet". */
    private function styleNumbering(DOMDocument $styles): array
    {
        $x = $this->xpath($styles);
        $map = [];
        foreach ($x->query("//w:style[@w:type='paragraph']") as $style) {
            $numId = $this->val($x, 'w:pPr/w:numPr/w:numId', $style);
            if ($numId !== null) {
                $map[$style->getAttributeNS(self::W, 'styleId')] = [$numId, (int) ($this->val($x, 'w:pPr/w:numPr/w:ilvl', $style) ?? 0)];
            }
        }

        return $map;
    }

    /**
     * The paper's title (first centred paragraph, after an optional UDK line) is set in 14.5 pt bold,
     * so it is the largest text on the first page — Google Scholar reads the title from there.
     * WordToPdf keeps this size while the rest of the text gets the journal's 12 pt.
     */
    private function markTitle(DOMDocument $document): void
    {
        $x = $this->xpath($document);
        foreach ($x->query('//w:body/*') as $node) {
            if ($node->localName === 'tbl') {
                return;
            }
            if ($node->localName !== 'p') {
                continue;
            }
            $text = trim($node->textContent);
            if ($text === '' || preg_match('/^(UDK|UDC|УДК|ӮДК)\b/iu', $text)) {
                continue;
            }
            if ($this->val($x, 'w:pPr/w:jc', $node) !== 'center') {
                return; // no centred title: leave the document as it is
            }
            foreach ($x->query('.//w:r', $node) as $run) {
                $props = $x->query('w:rPr', $run)->item(0);
                if (! $props) {
                    $props = $document->createElementNS(self::W, 'w:rPr');
                    $run->insertBefore($props, $run->firstChild);
                }
                foreach ($x->query('w:sz | w:szCs | w:b', $props) as $old) {
                    $props->removeChild($old);
                }
                $props->appendChild($document->createElementNS(self::W, 'w:b'));
                foreach (['w:sz', 'w:szCs'] as $tag) {
                    $sz = $document->createElementNS(self::W, $tag);
                    $sz->setAttributeNS(self::W, 'w:val', '29');
                    $props->appendChild($sz);
                }
            }

            return;
        }
    }

    /**
     * PHPWord collects footnotes at the very end of the document. Here every footnote (and endnote)
     * becomes a superscript number in the text and a small note printed right after the paragraph
     * that cites it — or after the table, when the mark is on the table's caption (a source note).
     * The notes are written in 9.5 pt, a size the journal template keeps (see WordToPdf).
     */
    private function inlineNotes(DOMDocument $document, array $sources): void
    {
        $x = $this->xpath($document);
        $refs = iterator_to_array($x->query('//w:body//w:footnoteReference | //w:body//w:endnoteReference'));
        if (! $refs) {
            return;
        }

        $texts = [];
        foreach ($sources as $kind => $dom) {
            if (! $dom) {
                continue;
            }
            $nx = $this->xpath($dom);
            foreach ($nx->query('//w:'.$kind) as $note) {
                $paragraphs = [];
                foreach ($nx->query('.//w:p', $note) as $p) {
                    $t = '';
                    foreach ($nx->query('.//w:t', $p) as $node) {
                        $t .= $node->textContent;
                    }
                    if (trim($t) !== '') {
                        $paragraphs[] = trim($t);
                    }
                }
                $texts[$kind][$note->getAttributeNS(self::W, 'id')] = implode(' ', $paragraphs);
            }
        }

        $body = $x->query('//w:body')->item(0);
        $pending = [];   // body-level block => [[number, text], ...]
        $n = 0;
        foreach ($refs as $ref) {
            $kind = $ref->localName === 'endnoteReference' ? 'endnote' : 'footnote';
            $text = $texts[$kind][$ref->getAttributeNS(self::W, 'id')] ?? '';
            $n++;

            // The mark: a superscript number in place of the reference.
            $run = $ref->parentNode;
            $props = $x->query('w:rPr', $run)->item(0);
            if (! $props) {
                $props = $document->createElementNS(self::W, 'w:rPr');
                $run->insertBefore($props, $run->firstChild);
            }
            foreach ($x->query('w:vertAlign', $props) as $old) {
                $props->removeChild($old);
            }
            $sup = $document->createElementNS(self::W, 'w:vertAlign');
            $sup->setAttributeNS(self::W, 'w:val', 'superscript');
            $props->appendChild($sup);
            $t = $document->createElementNS(self::W, 'w:t');
            $t->appendChild($document->createTextNode((string) $n));
            $run->replaceChild($t, $ref);

            if ($text === '') {
                continue;
            }
            // Where the note goes: after the body-level paragraph or table holding the mark…
            $block = $run;
            while ($block->parentNode && ! $block->parentNode->isSameNode($body)) {
                $block = $block->parentNode;
            }
            // …and after the table when the mark is on a caption right above it.
            if ($block->localName === 'p') {
                $next = $block->nextSibling;
                while ($next && ($next->nodeType !== XML_ELEMENT_NODE || ($next->localName === 'p' && trim($next->textContent) === ''))) {
                    $next = $next->nextSibling;
                }
                if ($next && $next->localName === 'tbl') {
                    $block = $next;
                }
            }
            $key = spl_object_id($block);
            $pending[$key]['block'] = $block;
            $pending[$key]['notes'][] = [$n, $text];
        }

        foreach ($pending as $entry) {
            $after = $entry['block'];
            foreach ($entry['notes'] as [$number, $text]) {
                $p = $document->createElementNS(self::W, 'w:p');
                $pPr = $document->createElementNS(self::W, 'w:pPr');
                $jc = $document->createElementNS(self::W, 'w:jc');
                $jc->setAttributeNS(self::W, 'w:val', 'left');
                $pPr->appendChild($jc);
                $p->appendChild($pPr);
                foreach ([[$number, true], [' '.$text, false]] as [$value, $superscript]) {
                    $r = $document->createElementNS(self::W, 'w:r');
                    $rPr = $document->createElementNS(self::W, 'w:rPr');
                    $sz = $document->createElementNS(self::W, 'w:sz');
                    $sz->setAttributeNS(self::W, 'w:val', '19');
                    $rPr->appendChild($sz);
                    if ($superscript) {
                        $va = $document->createElementNS(self::W, 'w:vertAlign');
                        $va->setAttributeNS(self::W, 'w:val', 'superscript');
                        $rPr->appendChild($va);
                    }
                    $r->appendChild($rPr);
                    $t = $document->createElementNS(self::W, 'w:t');
                    $t->setAttribute('xml:space', 'preserve');
                    $t->appendChild($document->createTextNode((string) $value));
                    $r->appendChild($t);
                    $p->appendChild($r);
                }
                $after->parentNode->insertBefore($p, $after->nextSibling);
                $after = $p;
            }
        }
    }

    /**
     * Word equations (Office Math) make PHPWord fail on the whole document.
     * Each equation is replaced by its text in one italic run, so the paper still converts.
     */
    private function flattenMath(DOMDocument $document): void
    {
        $x = $this->xpath($document);
        $x->registerNamespace('m', 'http://schemas.openxmlformats.org/officeDocument/2006/math');
        foreach (iterator_to_array($x->query('//m:oMathPara | //m:oMath[not(ancestor::m:oMathPara)]')) as $math) {
            $text = '';
            foreach ($x->query('.//m:t', $math) as $t) {
                $text .= $t->textContent;
            }
            $run = $document->createElementNS(self::W, 'w:r');
            $props = $document->createElementNS(self::W, 'w:rPr');
            $props->appendChild($document->createElementNS(self::W, 'w:i'));
            $run->appendChild($props);
            $t = $document->createElementNS(self::W, 'w:t');
            $t->setAttribute('xml:space', 'preserve');
            $t->appendChild($document->createTextNode($text));
            $run->appendChild($t);
            $math->parentNode->replaceChild($run, $math);
        }
    }

    private function writeListMarkers(DOMDocument $document, array $formats, array $styleNumbering): void
    {
        $x = $this->xpath($document);
        $counters = [];

        foreach ($x->query('//w:body//w:p') as $p) {
            $numId = $this->val($x, 'w:pPr/w:numPr/w:numId', $p);
            $ilvl = (int) ($this->val($x, 'w:pPr/w:numPr/w:ilvl', $p) ?? 0);
            if ($numId === null && ($style = $this->val($x, 'w:pPr/w:pStyle', $p)) && isset($styleNumbering[$style])) {
                [$numId, $ilvl] = $styleNumbering[$style];
            }
            if ($numId === null || $numId === '0') {
                continue;
            }

            [$format, $text, $start] = $formats[$numId][$ilvl] ?? ['bullet', '•', 1];
            $counters[$numId][$ilvl] = ($counters[$numId][$ilvl] ?? $start - 1) + 1;
            foreach (array_keys($counters[$numId]) as $level) {
                if ($level > $ilvl) {
                    unset($counters[$numId][$level]); // a deeper list restarts after its parent item
                }
            }

            if ($format === 'bullet' || $format === 'none') {
                $label = $format === 'none' ? '' : '•';
            } else {
                $label = preg_replace_callback('/%(\d)/', function ($m) use ($counters, $numId, $formats) {
                    $level = (int) $m[1] - 1;
                    $fmt = $formats[$numId][$level][0] ?? 'decimal';

                    return $this->formatNumber($counters[$numId][$level] ?? 1, $fmt);
                }, $text);
            }
            if ($label === '') {
                continue;
            }

            $run = $document->createElementNS(self::W, 'w:r');
            $t = $document->createElementNS(self::W, 'w:t', htmlspecialchars(str_repeat("\u{00A0}", 4 * $ilvl).$label."\u{00A0}\u{00A0}"));
            $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $run->appendChild($t);

            $pPr = $x->query('w:pPr', $p)->item(0);
            $pPr ? $p->insertBefore($run, $pPr->nextSibling) : $p->insertBefore($run, $p->firstChild);
            foreach ($x->query('w:pPr/w:numPr', $p) as $numPr) {
                $numPr->parentNode->removeChild($numPr);
            }
        }
    }

    private function formatNumber(int $n, string $format): string
    {
        return match ($format) {
            'lowerLetter' => $this->letters($n),
            'upperLetter' => strtoupper($this->letters($n)),
            'lowerRoman' => strtolower($this->roman($n)),
            'upperRoman' => $this->roman($n),
            default => (string) $n,
        };
    }

    private function letters(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(97 + $n % 26).$s;
            $n = intdiv($n, 26);
        }

        return $s;
    }

    private function roman(int $n): string
    {
        $map = ['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1];
        $s = '';
        foreach ($map as $r => $v) {
            while ($n >= $v) {
                $s .= $r;
                $n -= $v;
            }
        }

        return $s;
    }

    private function val(DOMXPath $x, string $path, DOMElement $context): ?string
    {
        $node = $x->query($path, $context)->item(0);

        return $node instanceof DOMElement ? $node->getAttributeNS(self::W, 'val') : null;
    }
}
