<?php

namespace App\Services\Originality;

/**
 * Document fingerprints by winnowing (Schleimer, Wilkerson & Aiken 2003, the method behind MOSS):
 * every run of K consecutive words is hashed, and from each window of W hashes the minimum is kept.
 * Any shared passage of at least K + W - 1 words is guaranteed to produce a shared fingerprint.
 */
class Fingerprinter
{
    public const K = 5;
    public const W = 4;

    /**
     * Words of the text: 'norm' for matching (lower case, no apostrophes) and 'orig' for showing passages.
     *
     * @return array{norm: string[], orig: string[]}
     */
    public function words(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}\'‘’ʻʼ`´]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $norm = $orig = [];
        foreach ($tokens as $token) {
            // o‘zbek / o'zbek / oʻzbek all become "ozbek"; Uzbek writes the same word with many apostrophes.
            $n = mb_strtolower(preg_replace('/[\'‘’ʻʼ`´]/u', '', $token));
            if ($n === '' || (mb_strlen($n) === 1 && ! preg_match('/\p{N}/u', $n))) {
                continue;
            }
            $norm[] = $n;
            $orig[] = $token;
        }

        return ['norm' => $norm, 'orig' => $orig];
    }

    /** @return array<int, array{0: int, 1: int}> list of [hash, word position] */
    public function fingerprints(array $normWords): array
    {
        $count = count($normWords) - self::K + 1;
        if ($count <= 0) {
            return [];
        }

        $hashes = [];
        for ($i = 0; $i < $count; $i++) {
            $hashes[$i] = crc32(implode(' ', array_slice($normWords, $i, self::K)));
        }

        $selected = [];
        $last = -1;
        $windows = max(1, $count - self::W + 1);
        for ($start = 0; $start < $windows; $start++) {
            $minPos = $start;
            $end = min($count, $start + self::W);
            for ($i = $start; $i < $end; $i++) {
                if ($hashes[$i] <= $hashes[$minPos]) {
                    $minPos = $i; // rightmost minimum, as in the paper
                }
            }
            if ($minPos !== $last) {
                $selected[] = [$hashes[$minPos], $minPos];
                $last = $minPos;
            }
        }

        return $selected;
    }
}
