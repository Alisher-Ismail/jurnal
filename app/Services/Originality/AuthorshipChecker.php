<?php

namespace App\Services\Originality;

use App\Models\Paper;
use App\Models\PlagiarismDocument;
use Illuminate\Support\Facades\DB;

/**
 * Answers the question "is this researcher submitting someone else's paper?".
 * When a submission largely matches an indexed text, the authors of that text are compared
 * with the submitted authors:
 *  - article / earlier submission: their author lists;
 *  - proceedings volume: the names printed just before the matched article inside the PDF.
 * Names are compared by surname, across Latin/Cyrillic and common spelling variants.
 */
class AuthorshipChecker
{
    private const CYRILLIC = [
        'ш' => 'sh', 'ч' => 'ch', 'щ' => 'sh', 'ж' => 'j', 'ё' => 'yo', 'ю' => 'yu', 'я' => 'ya', 'ц' => 'ts', 'ў' => 'o', 'қ' => 'q',
        'ғ' => 'g', 'ҳ' => 'h', 'х' => 'x', 'й' => 'y', 'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
        'з' => 'z', 'и' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's',
        'т' => 't', 'у' => 'u', 'ф' => 'f', 'ы' => 'i', 'э' => 'e', 'ъ' => '', 'ь' => '',
    ];

    /** Patronymic markers and particles that are not surnames. */
    private const NOT_NAMES = ['ogli', 'ugli', 'qizi', 'kizi', 'oglu', 'and', 'va', 'и', 'et', 'al'];

    public function __construct(private Fingerprinter $fingerprinter)
    {
    }

    /**
     * @return array{type: ?string, hold: bool, items: array}
     *   type: 'mismatch' (others' text), 'web' (mostly found online), 'same_authors' (own earlier text) or null
     */
    public function analyze(Paper $paper, string $text, array $similarity, array $web): array
    {
        $threshold = (float) config('journal.authorship_match_percent', 25);
        $submitted = $this->surnames((string) $paper->authors);
        $items = [];

        $hashes = null;
        $coverage = (float) config('journal.corpus.match_coverage', 0.5);
        $relevant = array_filter($similarity['sources'] ?? [], fn ($s) => $s['type'] === 'external'
            ? ($s['coverage'] ?? 0) >= $coverage     // most of a published abstract is in the submission
            : $s['percent'] >= $threshold);
        foreach (array_slice($relevant, 0, 4) as $source) {
            $doc = PlagiarismDocument::find($source['document_id']);
            if (! $doc) {
                continue;
            }

            $item = [
                'type' => $doc->type, 'ref_id' => $doc->ref_id, 'title' => $doc->title, 'percent' => $source['percent'],
                'coverage' => $source['coverage'] ?? null,
                'source_authors' => $doc->authors, 'context' => null, 'same_submitter' => false,
            ];

            if ($doc->type === 'issue') {
                // Proceedings: look for the submitted surnames right before the copied article inside the volume.
                $hashes ??= array_values(array_unique(array_column($this->fingerprinter->fingerprints($this->fingerprinter->words($text)['norm']), 0)));
                [$found, $context] = $this->namesNearMatch($doc, $hashes, $submitted);
                $item['context'] = $context;
                $item['overlap'] = $found;
            } else {
                $item['overlap'] = $this->overlap($submitted, $this->surnames((string) $doc->authors));
                if ($doc->type === 'paper' && ($other = Paper::find($doc->ref_id))) {
                    $item['same_submitter'] = ($paper->telegram_chat_id && (string) $paper->telegram_chat_id === (string) $other->telegram_chat_id)
                        || ($paper->email && strcasecmp((string) $paper->email, (string) $other->email) === 0);
                    $item['overlap'] = $item['overlap'] || $item['same_submitter'];
                }
            }
            $items[] = $item;
        }

        $type = null;
        // Being an author of the *published* source settles it: an earlier unpublished submission of the
        // same text by other people then points at them, not at this author.
        $publishedAuthor = collect($items)->contains(fn ($i) => $i['overlap'] && in_array($i['type'], ['article', 'issue', 'external'], true));
        if (! $publishedAuthor && collect($items)->contains(fn ($i) => ! $i['overlap'])) {
            $type = 'mismatch';
        } elseif (($web['checked'] ?? 0) >= 3 && ($web['found'] ?? 0) / max(1, $web['checked']) >= (float) config('journal.web_conflict_share', 0.5)) {
            $type = 'web';
        } elseif ($items) {
            $type = 'same_authors';
        }

        return ['type' => $type, 'hold' => in_array($type, ['mismatch', 'web'], true), 'items' => $items];
    }

    /** @return string[] normalized surnames (Latin, lower case, no apostrophes) */
    public function surnames(string $authors): array
    {
        $result = [];
        foreach (preg_split('/[,;\n]+|\s+(?:and|va|и)\s+/u', $authors) ?: [] as $person) {
            $raw = array_values(array_filter(array_map(fn ($t) => $this->normalize($t), preg_split('/[\s.]+/u', $person) ?: [])));
            // Patronymics are not surnames: "Nasirdin oʻgʻli", "Shokirovich", "Rustamovna".
            foreach ($raw as $i => $t) {
                if (in_array($t, ['ogli', 'ugli', 'qizi', 'kizi', 'oglu'], true) && $i > 0) {
                    $raw[$i - 1] = '';
                }
            }
            $tokens = array_values(array_filter(
                $raw,
                fn ($t) => mb_strlen($t) >= 3 && ! in_array($t, self::NOT_NAMES, true) && ! preg_match('/(ovich|evich|ovna|evna|ichna)$/', $t)
            ));
            if (! $tokens) {
                continue;
            }
            // Prefer tokens that look like surnames (Ismoilov, Karimova, Abduraxmonov, Петров, ...).
            $likely = array_filter($tokens, fn ($t) => preg_match('/(ov|ova|ev|eva|yev|yeva|in|ina|iy|kiy|skiy|skaya|enko|uk|ko)$/', $t));
            foreach ($likely ?: $tokens as $t) {
                $result[] = $t;
            }
        }

        return array_values(array_unique($result));
    }

    public function normalize(string $word): string
    {
        $word = mb_strtolower($word);
        $word = strtr($word, self::CYRILLIC);

        return (string) preg_replace('/[^a-z0-9]/', '', $this->latin($word));
    }

    /** Fold Uzbek spelling variants: ismoilov / ismailov / ismoyilov, xo‘ja / xoja, sh/ch stay. */
    private function latin(string $word): string
    {
        return str_replace(['oyi', 'aye', 'yo', 'ye'], ['oi', 'ae', 'o', 'e'], $word);
    }

    private function same(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $len = min(strlen($a), strlen($b));
        if ($len < 5) {
            return false;
        }

        return levenshtein($a, $b) <= ($len >= 8 ? 2 : 1);
    }

    private function overlap(array $a, array $b): bool
    {
        foreach ($a as $x) {
            foreach ($b as $y) {
                if ($this->same($x, $y)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Inside a proceedings volume, find where the copied text sits (densest run of matching fingerprints)
     * and check whether any submitted surname is printed in the title block just before it.
     *
     * @return array{0: bool, 1: ?string} [surname found, text before the matched passage]
     */
    private function namesNearMatch(PlagiarismDocument $doc, array $hashes, array $submitted): array
    {
        $positions = [];
        foreach (array_chunk($hashes, 1000) as $chunk) {
            foreach (DB::table('plagiarism_fingerprints')->where('document_id', $doc->id)->whereIn('hash', $chunk)->pluck('position') as $p) {
                $positions[] = (int) $p;
            }
        }
        $text = $doc->text();
        if (! $positions || $text === '') {
            return [false, null];
        }
        sort($positions);

        // Start of the densest cluster: the copied article (scattered common phrases elsewhere are ignored).
        $best = $positions[0];
        $bestCount = 0;
        foreach ($positions as $i => $start) {
            $count = 0;
            for ($k = $i; $k < count($positions) && $positions[$k] <= $start + 400; $k++) {
                $count++;
            }
            if ($count > $bestCount) {
                [$best, $bestCount] = [$start, $count];
            }
        }

        // In a fully copied article every stretch is equally dense, so the densest window can sit in the
        // middle of it: walk back while matches stay contiguous to reach the real start of the copied text.
        $index = array_search($best, $positions, true);
        while ($index > 0 && $positions[$index] - $positions[$index - 1] <= 40) {
            $index--;
        }
        $best = $positions[$index];

        $words = $this->fingerprinter->words($text);
        // The title block (CAPS title, authors, affiliation, e-mail) sits just before or at the start of
        // the copied text. Anchor the window at the article's ALL-CAPS title so it never reaches the
        // previous article's reference list, which is full of other people's surnames.
        $from = max(0, $best - 50);
        $run = 0;
        for ($i = $best - 1; $i >= max(0, $best - 150); $i--) {
            $w = $words['orig'][$i] ?? '';
            $isCaps = preg_match('/\p{L}{2,}/u', $w) && mb_strtoupper($w) === $w;
            $run = $isCaps ? $run + 1 : 0;
            if ($run >= 3) {
                $from = $i; // keep extending while the title continues upwards
                while ($from > 0 && preg_match('/\p{L}{2,}/u', $words['orig'][$from - 1]) && mb_strtoupper($words['orig'][$from - 1]) === $words['orig'][$from - 1]) {
                    $from--;
                }
                break;
            }
        }
        $window = array_map(fn ($w) => $this->normalize($w), array_slice($words['orig'], $from, $best - $from + 40));
        $found = $this->overlap($submitted, array_values(array_filter($window, fn ($w) => strlen($w) >= 3)));
        $context = implode(' ', array_slice($words['orig'], $from, min(70, $best - $from + 10)));

        return [$found, $context !== '' ? '… '.$context : null];
    }
}
