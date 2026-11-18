<?php

namespace App\Services\Originality;

/**
 * Built-in "AI-written" indicator from style statistics. It runs on the server and sends nothing anywhere.
 * These signals are typical of machine-generated prose, but humans can show them too: the result is a
 * hint for the editor to read more carefully, never proof.
 */
class AiTextAnalyzer
{
    public const MIN_WORDS = 300;

    /** Stock phrases language models overuse (English, Russian, Uzbek). */
    private const PHRASES = [
        // English
        'delve', 'delves', 'delving', 'it is important to note', 'it is worth noting', 'plays a crucial role', 'plays a pivotal role',
        'in today\'s rapidly', 'in the ever-evolving', 'ever-evolving', 'rapidly evolving', 'a testament to', 'navigate the complexities',
        'navigating the', 'tapestry', 'multifaceted', 'seamlessly', 'harness the power', 'underscores the importance', 'foster',
        'in conclusion', 'comprehensive overview', 'shed light on', 'pave the way', 'paving the way', 'a myriad of', 'myriad',
        'in the realm of', 'realm', 'holistic', 'robust framework', 'cutting-edge', 'game-changer', 'key takeaways', 'intricate',
        // Russian
        'важно отметить', 'стоит отметить', 'следует подчеркнуть', 'играет ключевую роль', 'играет важную роль', 'в современном мире',
        'в условиях стремительно', 'является неотъемлемой частью', 'неотъемлемой частью', 'в заключение', 'таким образом можно',
        'комплексный подход', 'многогранн', 'открывает новые возможности', 'в эпоху цифровизации',
        // Uzbek
        'shuni taʼkidlash joizki', 'shuni ta\'kidlash joizki', 'muhim ahamiyat kasb etadi', 'hal qiluvchi rol oʻynaydi',
        'hal qiluvchi rol o\'ynaydi', 'muhim rol oʻynaydi', 'muhim rol o\'ynaydi', 'zamonaviy dunyoda', 'xulosa qilib aytganda',
        'keng qamrovli', 'yangi imkoniyatlar ochadi', 'ajralmas qismi', 'raqamlashtirish davrida',
    ];

    /** Sentence-opening connectives (overused at the start of sentences by language models). */
    private const OPENERS = [
        'moreover', 'furthermore', 'additionally', 'in addition', 'overall', 'consequently', 'ultimately', 'notably', 'importantly',
        'кроме того', 'более того', 'также', 'таким образом', 'в целом', 'помимо этого', 'следовательно',
        'bundan tashqari', 'shuningdek', 'shu bilan birga', 'umuman olganda', 'natijada', 'xususan', 'qolaversa',
    ];

    /** @return array{score: ?int, words: int, signals: array} */
    public function analyze(string $text): array
    {
        // Ignore the reference list: it is not the author's prose.
        if (preg_match('/\b(Adabiyotlar|Foydalanilgan adabiyotlar|Литература|Список литературы|References|Bibliography)\b/iu', $text, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > strlen($text) * 0.4) {
            $text = substr($text, 0, $m[0][1]);
        }
        $flat = trim(preg_replace('/\s+/u', ' ', $text));
        $lower = mb_strtolower($flat);
        $words = preg_split('/[^\p{L}\p{N}\'ʻ’]+/u', $flat, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $wordCount = count($words);

        if ($wordCount < self::MIN_WORDS) {
            return ['score' => null, 'words' => $wordCount, 'signals' => []];
        }

        $sentences = array_values(array_filter(
            preg_split('/(?<=[.!?])\s+(?=[\p{Lu}\d«"“])/u', $flat) ?: [],
            fn ($s) => count(preg_split('/\s+/u', trim($s))) >= 4
        ));
        $lengths = array_map(fn ($s) => count(preg_split('/\s+/u', trim($s))), $sentences);

        // 1. Uniform sentence length (low "burstiness"). Human academic prose: coefficient of variation ~0.5–0.8.
        $mean = $lengths ? array_sum($lengths) / count($lengths) : 0;
        $cv = $mean > 0 ? sqrt(array_sum(array_map(fn ($l) => ($l - $mean) ** 2, $lengths)) / count($lengths)) / $mean : 0.6;
        $uniformity = $this->scale($cv, 0.65, 0.30);

        // 2. Stock phrases per 1000 words.
        $phraseHits = [];
        foreach (self::PHRASES as $phrase) {
            $n = mb_substr_count($lower, $phrase);
            if ($n) {
                $phraseHits[$phrase] = $n;
            }
        }
        $phraseRate = array_sum($phraseHits) / $wordCount * 1000;
        $phrases = $this->scale($phraseRate, 0.5, 5.0);

        // 3. Sentences opening with a connective ("Moreover, ...", "Bundan tashqari, ...").
        $openerCount = 0;
        foreach ($sentences as $s) {
            $start = mb_strtolower(mb_substr(trim($s), 0, 30));
            foreach (self::OPENERS as $o) {
                if (str_starts_with($start, $o)) {
                    $openerCount++;
                    break;
                }
            }
        }
        $openerShare = $sentences ? $openerCount / count($sentences) : 0;
        $openers = $this->scale($openerShare, 0.06, 0.25);

        // 4. Repetitive sentence starts (same first two words used again and again).
        $starts = array_count_values(array_map(fn ($s) => implode(' ', array_slice(preg_split('/\s+/u', mb_strtolower(trim($s))), 0, 2)), $sentences));
        $repeated = array_sum(array_filter($starts, fn ($n) => $n >= 3));
        $repeatShare = $sentences ? $repeated / count($sentences) : 0;
        $repetition = $this->scale($repeatShare, 0.08, 0.35);

        // 5. Vocabulary variety (moving-average type/token ratio over 100-word windows). Very even, mid-level
        //    variety is typical of generated text; real papers swing between dense terminology and plain passages.
        $ttrs = [];
        $normWords = array_map('mb_strtolower', $words);
        for ($i = 0; $i + 100 <= count($normWords); $i += 50) {
            $window = array_slice($normWords, $i, 100);
            $ttrs[] = count(array_unique($window)) / 100;
        }
        $ttrMean = $ttrs ? array_sum($ttrs) / count($ttrs) : 0;
        $ttrSd = $ttrs ? sqrt(array_sum(array_map(fn ($t) => ($t - $ttrMean) ** 2, $ttrs)) / count($ttrs)) : 0;
        $evenness = $this->scale($ttrSd, 0.06, 0.02);

        $signals = [
            ['key' => 'uniformity', 'label' => 'Sentence length is very uniform', 'value' => round($cv, 2), 'display' => 'CV '.round($cv, 2), 'weight' => 0.30, 'level' => $uniformity],
            ['key' => 'phrases', 'label' => 'Typical AI phrases', 'value' => round($phraseRate, 1), 'display' => round($phraseRate, 1).' / 1000', 'weight' => 0.30, 'level' => $phrases,
                'examples' => array_slice(array_keys($phraseHits), 0, 8)],
            ['key' => 'openers', 'label' => 'Sentences start with connectives', 'value' => round($openerShare * 100), 'display' => round($openerShare * 100).'%', 'weight' => 0.15, 'level' => $openers],
            ['key' => 'repetition', 'label' => 'Repetitive sentence openings', 'value' => round($repeatShare * 100), 'display' => round($repeatShare * 100).'%', 'weight' => 0.10, 'level' => $repetition],
            ['key' => 'evenness', 'label' => 'Unusually even vocabulary', 'value' => round($ttrSd, 3), 'display' => 'σ '.round($ttrSd, 3), 'weight' => 0.15, 'level' => $evenness],
        ];

        $score = 0.0;
        foreach ($signals as $s) {
            $score += $s['weight'] * $s['level'];
        }

        return ['score' => (int) round($score * 100), 'words' => $wordCount, 'signals' => $signals];
    }

    /** Map a value onto 0..1 between a "human" end and an "AI" end (either direction). */
    private function scale(float $value, float $human, float $ai): float
    {
        if ($human == $ai) {
            return 0.0;
        }

        return max(0.0, min(1.0, ($value - $human) / ($ai - $human)));
    }
}
