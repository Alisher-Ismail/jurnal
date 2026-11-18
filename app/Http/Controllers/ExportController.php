<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Support\JournalProfile;
use App\Support\Locale;
use Illuminate\Http\Request;

/**
 * Public article metadata as JSON (the same information as the article pages and the OAI feed),
 * used to deposit articles to Zenodo. Filters: issue=<id>, author=<text>, ids=1,2,3; paged by offset/limit.
 */
class ExportController extends Controller
{
    public function articles(Request $request)
    {
        $query = Article::with('issue')->orderBy('id');
        if ($issue = $request->query('issue')) {
            $query->where('volume', (string) (int) $issue);
        }
        if ($author = trim((string) $request->query('author'))) {
            $query->where('author', 'like', '%'.addcslashes($author, '%_\\').'%');
        }
        if ($ids = $request->query('ids')) {
            $query->whereIn('id', array_map('intval', explode(',', (string) $ids)));
        }
        $total = (clone $query)->count();
        $offset = max(0, (int) $request->query('offset', 0));
        $limit = min(200, max(1, (int) $request->query('limit', 50)));

        $items = $query->skip($offset)->take($limit)->get()->map(function (Article $a) {
            $issue = $a->issue;
            preg_match('/Vol\.?\s*(\d+)(?:\s*No\.?\s*(\d+))?/i', (string) $issue?->volume, $vol);

            return [
                'id' => $a->id,
                'title' => $a->title,
                'authors' => $a->authorList(),
                'abstract' => trim((string) $a->abstract),
                'keywords' => $a->keywordList(),
                'language' => ['uz' => 'uzb', 'ru' => 'rus', 'en' => 'eng'][$a->languageCode()],
                'date' => optional($a->publishedAt())->format('Y-m-d'),
                'issue' => $issue ? [
                    'id' => $issue->id,
                    'label' => $issue->volume,
                    'volume' => $vol[1] ?? null,
                    'number' => $vol[2] ?? null,
                    'title' => $issue->title,
                    'url' => Locale::route('maqolavolume', $issue->id, 'uz'),
                ] : null,
                'url' => Locale::route('maqola', $a->id, 'uz'),
                'pdf' => $a->pdfUrl(),
                'doi' => $a->doi,
            ];
        });

        return response()->json([
            'journal' => config('journal.name'),
            'publisher' => config('journal.publisher'),
            'issn' => JournalProfile::issnNumber(),
            'license' => ['name' => config('journal.license'), 'url' => config('journal.license_url')],
            'total' => $total,
            'offset' => $offset,
            'items' => $items,
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
