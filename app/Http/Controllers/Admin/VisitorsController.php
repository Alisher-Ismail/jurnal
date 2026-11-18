<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Panel → Visitors: how many people visited the website, what they read and where they came from. */
class VisitorsController extends Controller
{
    public const RANGES = [7, 30, 90, 365];

    public function __invoke(Request $request)
    {
        $range = in_array((int) $request->query('days'), self::RANGES, true) ? (int) $request->query('days') : 30;
        if (! Schema::hasTable('visit_days')) {
            return view('admin.visitors', ['ready' => false, 'range' => $range]);
        }

        $today = now(\App\Services\VisitTracker::TZ)->startOfDay();
        $from = $today->copy()->subDays($range - 1);
        $rows = DB::table('visit_days')->where('day', '>=', $from->toDateString())->get()->keyBy(fn ($r) => (string) $r->day);

        // One bar per day; per week for the longer ranges.
        $bars = [];
        for ($d = $from->copy(); $d->lte($today); $d->addDay()) {
            $key = $range > 90 ? $d->copy()->startOfWeek()->toDateString() : $d->toDateString();
            $row = $rows[$d->toDateString()] ?? null;
            $bars[$key] ??= ['day' => $key, 'visitors' => 0, 'views' => 0, 'downloads' => 0];
            foreach (['visitors', 'views', 'downloads'] as $c) {
                $bars[$key][$c] += (int) ($row->$c ?? 0);
            }
        }

        $sum = fn (string $c) => (int) $rows->sum($c);
        $day = fn (Carbon $d, string $c) => (int) ($rows[$d->toDateString()]->$c ?? 0);
        $counts = fn (string $kind, int $limit = 12) => DB::table('visit_counts')
            ->where('kind', $kind)->where('day', '>=', $from->toDateString())
            ->groupBy('item')->selectRaw('item, SUM(hits) AS hits')->orderByDesc('hits')->limit($limit)
            ->pluck('hits', 'item')->map(fn ($v) => (int) $v)->all();

        // The most read articles: page views and PDF downloads together.
        $views = $counts('article', 40);
        $downloads = $counts('download', 40);
        $ids = array_unique(array_merge(array_keys($views), array_keys($downloads)));
        $titles = Article::whereIn('id', $ids)->pluck('title', 'id');
        $articles = collect($ids)->filter(fn ($id) => isset($titles[$id]))->map(fn ($id) => [
            'id' => (int) $id, 'title' => $titles[$id], 'views' => $views[$id] ?? 0, 'downloads' => $downloads[$id] ?? 0,
        ])->sortByDesc(fn ($a) => $a['views'] + $a['downloads'])->take(15)->values();

        return view('admin.visitors', [
            'ready' => true,
            'range' => $range,
            'since' => DB::table('visit_days')->min('day'),
            'today' => ['visitors' => $day($today, 'visitors'), 'views' => $day($today, 'views'), 'downloads' => $day($today, 'downloads')],
            'yesterday' => $day($today->copy()->subDay(), 'visitors'),
            'total' => ['visitors' => $sum('visitors'), 'views' => $sum('views'), 'downloads' => $sum('downloads'), 'bots' => $sum('bots')],
            'bars' => array_values($bars),
            'weekly' => $range > 90,
            'articles' => $articles,
            'sources' => $counts('source'),
            'pages' => $counts('page'),
            'langs' => $counts('lang'),
            'devices' => $counts('device'),
            'bots' => $counts('bot'),
            'downloadSources' => $counts('dlsource'),
            // Average over the days actually counted (counting started on "since"), not the whole range.
            'countedDays' => max(1, min($range, (int) Carbon::parse(DB::table('visit_days')->min('day') ?? $today)->startOfDay()->diffInDays($today) + 1)),
        ]);
    }
}
