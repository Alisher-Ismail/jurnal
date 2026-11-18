<?php

namespace App\Services;

/**
 * Finds a paper's own abstract and keywords in the text of its PDF.
 *
 * Only a labelled abstract is taken ("Annotatsiya:", "Аннотация:", "Abstract." …), and only when the
 * paper's title appears just above it: many older PDFs were cut from whole issues and also contain the
 * beginning of the next paper, whose abstract must not be attached to this one.
 */
class AbstractExtractor
{
    private const ABSTRACT = '(?:annotatsiya|annotasiya|annotation|abstract|аннотация|анотация|аннотацияси|rezyume|резюме|xulosa\s+annotatsiya)';

    private const KEYWORDS = '(?:kalit\s+so\W{0,2}zlar|kalit\s+so\W{0,2}z|калит\s+с[ўу]злар|таянч\s+с[ўу]злар|tayanch\s+so\W{0,2}zlar|tayanch\s+tushunchalar|ключевые\s+слова|key\s*words|keywords)';

    private const NEXT_SECTION = '(?:kirish|введение|кириш|introduction|asosiy\s+qism|asosiy\s+matn|основная\s+часть|main\s+part|'.self::ABSTRACT.')';

    /** @return array{abstract: string, keywords: ?string}|null */
    public function find(string $text, string $title): ?array
    {
        $text = $this->repair($text);
        $titleWords = $this->words($title);

        $pattern = '/(?:^|\n|\.\s)\s*'.self::ABSTRACT.'\s*[:.\-–—]?\s*/iu';
        if (! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($matches[0] as [$heading, $byteOffset]) {
            $start = $byteOffset + strlen($heading);
            $before = mb_strcut($text, max(0, $byteOffset - 1500), min($byteOffset, 1500));
            if (! $this->titleNear($titleWords, $before)) {
                continue; // somebody else's abstract
            }

            $body = mb_strcut($text, $start, 6000);
            // The abstract ends at the keywords, a blank line or the next section heading.
            $end = strlen($body);
            foreach (['/\n\s*\n/u', '/(?:^|\n|\.\s)\s*'.self::KEYWORDS.'\s*[:.\-–—]/iu', '/\n\s*'.self::NEXT_SECTION.'\s*[:.\n]/iu'] as $stop) {
                if (preg_match($stop, $body, $m, PREG_OFFSET_CAPTURE, 40) && $m[0][1] < $end) {
                    $end = $m[0][1];
                }
            }
            $abstract = $this->clean(mb_strcut($body, 0, min($end, 4000)));
            if (mb_strlen($abstract) < 60 || mb_strlen($abstract) > 3500) {
                continue;
            }

            return ['abstract' => $abstract, 'keywords' => $this->keywords($text, $byteOffset)];
        }

        return null;
    }

    /**
     * For papers without an abstract: the opening of the paper's own text (about 1,000 characters,
     * ending at a sentence). Only when the paper's title is at the top of the PDF; the author block
     * under the title is skipped. Returns null for PDFs that start inside another paper.
     */
    public function excerpt(string $text, string $title): ?string
    {
        $text = $this->repair($text);
        $titleWords = $this->words($title);
        $needed = min(4, count($titleWords));
        if ($needed < 3) {
            return null;
        }
        $gap = "[\\s'\x{2018}\x{2019}\x{02BB}\x{02BC}`\x{201E}\x{201F}\x{2017}\x{2032}]*";
        $parts = array_map(fn ($word) => implode($gap, array_map(fn ($ch) => preg_quote($ch, '/'), mb_str_split($word))), array_slice($titleWords, 0, $needed));
        $head = mb_strcut($text, 0, 4000);
        if (! preg_match('/'.implode('[^\p{L}]+', $parts).'/iu', $head, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        // Body text starts at the first two consecutive long lines (wrapped paragraph text);
        // the author names and affiliations above it are short lines.
        $lines = preg_split('/\n/u', mb_strcut($text, $m[0][1] + strlen($m[0][0]), 9000));
        $start = null;
        foreach ($lines as $i => $line) {
            if (mb_strlen(trim($line)) >= 60 && mb_strlen(trim($lines[$i + 1] ?? '')) >= 60 && ! preg_match('/@|e-?mail|universitet|institut|университет|институт|fakultet|факультет|kafedra|кафедра/iu', $line)) {
                $start = $i;
                break;
            }
            if ($i > 25) {
                break;
            }
        }
        if ($start === null) {
            return null;
        }
        $body = $this->clean(implode("\n", array_slice($lines, $start, 40)));
        if (mb_strlen($body) < 300) {
            return null;
        }
        $body = mb_substr($body, 0, 1300);
        $cut = max(mb_strrpos($body, '. ') ?: 0, mb_strrpos($body, '.') === mb_strlen($body) - 1 ? mb_strlen($body) - 1 : 0);

        return $cut >= 500 ? mb_substr($body, 0, $cut + 1) : rtrim($body).'…';
    }

    /** Keywords just after (or, in some papers, just before) the abstract heading. */
    private function keywords(string $text, int $near): ?string
    {
        $window = mb_strcut($text, max(0, $near - 800), 4000);
        if (! preg_match('/'.self::KEYWORDS.'\s*[:.\-–—]\s*/iu', $window, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $rest = mb_strcut($window, $m[0][1] + strlen($m[0][0]), 700);
        $end = strlen($rest);
        foreach (['/\.\s*\n/u', '/\n\s*\n/u', '/\n\s*'.self::NEXT_SECTION.'\b/iu'] as $stop) {
            if (preg_match($stop, $rest, $s, PREG_OFFSET_CAPTURE) && $s[0][1] < $end) {
                $end = $s[0][1];
            }
        }
        $keywords = rtrim($this->clean(substr($rest, 0, $end)), ' .;,');

        return mb_strlen($keywords) >= 3 && mb_strlen($keywords) <= 400 ? $keywords : null;
    }

    /**
     * The title's first words appear, in order, just above the heading, with only the author block
     * between them. Single shared words are not enough ("davlat", "iqtisodiyot" also occur in university
     * names), and a title cited in the previous paper's reference list must not count.
     */
    private function titleNear(array $titleWords, string $before): bool
    {
        $needed = min(4, count($titleWords));
        if ($needed < 3) {
            return false; // too short to tell papers apart safely
        }
        // Letters may be split by stray spaces or broken apostrophes in the PDF text ("X O‘JALIGI").
        $gap = "[\\s'\x{2018}\x{2019}\x{02BB}\x{02BC}`\x{201E}\x{201F}\x{2017}\x{2032}]*";
        $parts = array_map(function ($word) use ($gap) {
            return implode($gap, array_map(fn ($ch) => preg_quote($ch, '/'), mb_str_split($word)));
        }, array_slice($titleWords, 0, $needed));
        if (! preg_match_all('/'.implode('[^\p{L}]+', $parts).'/iu', $before, $m, PREG_OFFSET_CAPTURE)) {
            return false;
        }
        [$found, $offset] = end($m[0]);
        $between = mb_strcut($before, $offset + strlen($found));

        return mb_strlen($between) <= 1100
            && ! preg_match('/\n\s*\d{1,3}\s*[.)]\s+\p{Lu}/u', $between)            // numbered reference entries
            && ! preg_match('/adabiyot|литератур|references|bibliograph|manbalar\b/iu', $between);
    }

    /** Lower-case words in order (apostrophes removed, so o‘zbek, o'zbek and ozbek match). */
    private function words(string $text, int $limit = 12): array
    {
        $text = mb_strtolower($this->repair($text));
        $text = preg_replace("/[\x{2018}\x{2019}\x{02BB}\x{02BC}'`\x{201E}\x{201F}\x{2017}\x{2032}]/u", '', $text);
        preg_match_all('/\p{L}+/u', $text, $m);

        return array_slice($m[0], 0, $limit);
    }

    /** Uzbek letters broken by old fonts: o„ g„ → o‘ g‘, ta‟ → ta’, o‗ → o‘. */
    private function repair(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/([oOgG])[\x{201E}\x{2017}`]/u', '$1‘', $text);

        return str_replace("\u{201F}", '’', $text);
    }

    private function clean(string $text): string
    {
        $text = preg_replace('/-\s*\n\s*/u', '-', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text, " \t\n:.-–—");
    }
}
