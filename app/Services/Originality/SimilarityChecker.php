<?php

namespace App\Services\Originality;

use App\Models\PlagiarismDocument;
use Illuminate\Support\Facades\DB;

/** Compares a text with the fingerprint index: overall similarity, top sources and matching passages. */
class SimilarityChecker
{
    public function __construct(private Fingerprinter $fingerprinter)
    {
    }

    /**
     * @param  int[]  $excludeDocumentIds  e.g. the submission itself and the article it became
     * @return array{similarity: float, sources: array, passages: array}
     */
    public function check(string $text, array $excludeDocumentIds = []): array
    {
        $words = $this->fingerprinter->words($text);
        $fingerprints = $this->fingerprinter->fingerprints($words['norm']);
        if (! $fingerprints) {
            return ['similarity' => 0.0, 'sources' => [], 'passages' => []];
        }

        // hash => [document ids]
        $hits = [];
        foreach (array_chunk(array_values(array_unique(array_column($fingerprints, 0))), 1000) as $chunk) {
            $rows = DB::table('plagiarism_fingerprints')->whereIn('hash', $chunk)
                ->when($excludeDocumentIds, fn ($q) => $q->whereNotIn('document_id', $excludeDocumentIds))
                ->select('hash', 'document_id')->distinct()->get();
            foreach ($rows as $row) {
                $hits[$row->hash][$row->document_id] = true;
            }
        }

        $total = count($fingerprints);
        $matched = 0;
        $perDocument = [];
        $docHashes = [];
        $matchedPositions = [];
        foreach ($fingerprints as [$hash, $position]) {
            if (! isset($hits[$hash])) {
                continue;
            }
            $matched++;
            $matchedPositions[$position] = array_keys($hits[$hash]);
            foreach ($hits[$hash] as $docId => $_) {
                $perDocument[$docId] = ($perDocument[$docId] ?? 0) + 1;
                $docHashes[$docId][$hash] = true;
            }
        }

        // Candidates: the strongest matches overall, plus every harvested abstract with several shared fingerprints.
        arsort($perDocument);
        $candidateIds = array_slice(array_keys($perDocument), 0, 40);
        foreach ($docHashes as $docId => $hashes) {
            if (count($hashes) >= 4) {
                $candidateIds[] = $docId;
            }
        }
        $docs = PlagiarismDocument::whereIn('id', array_unique($candidateIds))->get()->keyBy('id');

        $internal = $external = [];
        foreach ($docs as $docId => $doc) {
            $source = [
                'document_id' => $docId,
                'type' => $doc->type,
                'ref_id' => $doc->ref_id,
                'title' => $doc->title,
                'percent' => round(($perDocument[$docId] ?? 0) / $total * 100, 1),
                // Share of the source's own (sampled) fingerprints found in the submission.
                'coverage' => $doc->fingerprints ? round(count($docHashes[$docId] ?? []) / $doc->fingerprints, 2) : 0.0,
            ];
            if ($doc->type === 'external') {
                if ($source['coverage'] >= 0.3 && count($docHashes[$docId] ?? []) >= 4) {
                    $source['authors'] = $doc->authors;
                    $external[] = $source;
                }
            } else {
                $internal[] = $source;
            }
        }
        usort($internal, fn ($a, $b) => $b['percent'] <=> $a['percent']);
        usort($external, fn ($a, $b) => $b['coverage'] <=> $a['coverage']);
        $sources = array_merge(array_slice($internal, 0, 8), array_slice($external, 0, 6));

        return [
            'similarity' => round($matched / $total * 100, 1),
            'sources' => $sources,
            'passages' => $this->passages($matchedPositions, $words['orig'], $docs->all()),
        ];
    }

    /** Merge matching word ranges into readable passages (longest first). */
    private function passages(array $matchedPositions, array $origWords, array $docs): array
    {
        ksort($matchedPositions);
        $spans = [];
        foreach ($matchedPositions as $position => $docIds) {
            $end = $position + Fingerprinter::K - 1;
            $lastKey = array_key_last($spans);
            if ($lastKey !== null && $position <= $spans[$lastKey]['end'] + Fingerprinter::W) {
                $spans[$lastKey]['end'] = max($spans[$lastKey]['end'], $end);
                foreach ($docIds as $id) {
                    $spans[$lastKey]['docs'][$id] = ($spans[$lastKey]['docs'][$id] ?? 0) + 1;
                }
            } else {
                $spans[] = ['start' => $position, 'end' => $end, 'docs' => array_fill_keys($docIds, 1)];
            }
        }

        $spans = array_filter($spans, fn ($s) => $s['end'] - $s['start'] + 1 >= 8);
        usort($spans, fn ($a, $b) => ($b['end'] - $b['start']) <=> ($a['end'] - $a['start']));

        $passages = [];
        foreach (array_slice($spans, 0, 12) as $span) {
            arsort($span['docs']);
            $docId = array_key_first($span['docs']);
            $length = $span['end'] - $span['start'] + 1;
            $passages[] = [
                'words' => $length,
                'text' => implode(' ', array_slice($origWords, $span['start'], min($length, 70))).($length > 70 ? ' …' : ''),
                'document_id' => $docId,
                'title' => isset($docs[$docId]) ? $docs[$docId]->title : null,
            ];
        }

        return $passages;
    }
}
