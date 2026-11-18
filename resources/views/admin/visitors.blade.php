@extends('layouts.admin')

@section('title', __('Visitors'))

@section('content')
<style>
    .v-chart { width: 100%; height: 220px; display: block; }
    .v-chart .bar { fill: var(--brand); }
    .v-chart .bar-views { fill: color-mix(in srgb, var(--brand) 22%, transparent); }
    .v-chart .grid { stroke: var(--line); stroke-width: 1; }
    .v-chart text { fill: var(--faint); font: 11px var(--sans); }
    .v-legend { display: flex; gap: 16px; flex-wrap: wrap; font-size: 13px; color: var(--muted); margin-top: 8px; }
    .v-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 6px; vertical-align: -1px; }
    .v-list { display: grid; gap: 9px; margin: 0; padding: 0; list-style: none; }
    .v-list li { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 10px; font-size: 14px; color: var(--ink); }
    .v-list li b { font-variant-numeric: tabular-nums; }
    .v-list .track { grid-column: 1 / -1; height: 5px; border-radius: 3px; background: var(--line-2, var(--line)); overflow: hidden; }
    .v-list .track span { display: block; height: 100%; background: var(--brand); border-radius: 3px; }
    .v-side { display: grid; gap: 22px; }
    .v-side h2, .v-card h2 { font: 600 16px var(--sans); margin: 0 0 14px; }
    .kpi small { display: block; font-size: 12.5px; color: var(--muted); margin-top: 4px; }
    @media (max-width: 1100px) { .v-kpis { grid-template-columns: repeat(2, 1fr) !important; } }
</style>

@if (! $ready)
    <div class="card card-pad">
        <p>{{ __('Visitor statistics need a database update.') }} <a href="{{ route('admin.system') }}"><strong>{{ __('Open System → Run database update') }}</strong></a></p>
    </div>
@else
    @php
        $nf = fn ($n) => number_format((int) $n, 0, '.', ' ');
        $pageNames = ['home' => __('Home page'), 'article' => __('Article pages'), 'issue' => __('Issue pages'), 'archive' => __('Archive'), 'author' => __('Author pages'), 'search' => __('Search'), 'topic' => __('Topic pages'), 'guide' => __('Publishing guide'), 'submit' => __('Submit an article'), 'other' => __('Other pages')];
        $langNames = ['uz' => 'Oʻzbekcha', 'ru' => 'Русский', 'en' => 'English'];
        $deviceNames = ['mobile' => __('Phone / tablet'), 'desktop' => __('Computer')];
    @endphp

    <div class="toolbar" style="margin-bottom:18px">
        <div class="filters">
            @foreach (\App\Http\Controllers\Admin\VisitorsController::RANGES as $r)
                <a href="{{ route('admin.visitors', ['days' => $r]) }}" @class(['on' => $range === $r])>{{ $r === 365 ? __('1 year') : __(':n days', ['n' => $r]) }}</a>
            @endforeach
        </div>
        @if ($since)
            <span class="muted small">{{ __('Counting since :date', ['date' => \Illuminate\Support\Carbon::parse($since)->format('d.m.Y')]) }}</span>
        @endif
    </div>

    <div class="kpis v-kpis" style="grid-template-columns:repeat(4,1fr)">
        <div class="card kpi"><span><x-icon name="users"/> {{ __('Visitors today') }}</span><b>{{ $nf($today['visitors']) }}</b><small>{{ __('Yesterday') }}: {{ $nf($yesterday) }}</small></div>
        <div class="card kpi"><span><x-icon name="users"/> {{ __('Visitors') }} · {{ $range === 365 ? __('1 year') : __(':n days', ['n' => $range]) }}</span><b>{{ $nf($total['visitors']) }}</b><small>{{ __('≈ :n a day', ['n' => $nf(round($total['visitors'] / $countedDays))]) }}</small></div>
        <div class="card kpi"><span><x-icon name="eye"/> {{ __('Page views') }}</span><b>{{ $nf($total['views']) }}</b><small>{{ __('Today') }}: {{ $nf($today['views']) }}</small></div>
        <div class="card kpi"><span><x-icon name="download"/> {{ __('PDF downloads') }}</span><b>{{ $nf($total['downloads']) }}</b><small>{{ __('Today') }}: {{ $nf($today['downloads']) }}</small></div>
    </div>

    <div class="card card-pad v-card" style="margin-bottom:22px">
        <h2>{{ $weekly ? __('Visitors per week') : __('Visitors per day') }}</h2>
        @php
            $n = count($bars);
            $max = max(1, max(array_map(fn ($b) => max($b['visitors'], $b['views']), $bars)));
            $W = 1000; $H = 200; $top = 10; $bottom = 22; $plot = $H - $top - $bottom;
            $step = $W / max(1, $n); $bw = max(2, $step * 0.62);
            $labelEvery = max(1, (int) ceil($n / 10));
        @endphp
        <svg class="v-chart" viewBox="0 0 {{ $W }} {{ $H }}" preserveAspectRatio="none" role="img" aria-label="{{ __('Visitors per day') }}">
            @foreach ([0, .5, 1] as $g)
                <line class="grid" x1="0" x2="{{ $W }}" y1="{{ $top + $plot * (1 - $g) }}" y2="{{ $top + $plot * (1 - $g) }}"/>
            @endforeach
            @foreach ($bars as $i => $b)
                @php $x = $i * $step + ($step - $bw) / 2; $hv = $plot * $b['views'] / $max; $hu = $plot * $b['visitors'] / $max; @endphp
                <g>
                    <title>{{ \Illuminate\Support\Carbon::parse($b['day'])->format('d.m.Y') }}{{ $weekly ? ' – '.\Illuminate\Support\Carbon::parse($b['day'])->addDays(6)->format('d.m') : '' }}: {{ $b['visitors'] }} {{ __('visitors') }}, {{ $b['views'] }} {{ __('views') }}, {{ $b['downloads'] }} {{ __('downloads') }}</title>
                    <rect class="bar-views" x="{{ $x }}" y="{{ $top + $plot - $hv }}" width="{{ $bw }}" height="{{ $hv }}" rx="2"/>
                    <rect class="bar" x="{{ $x }}" y="{{ $top + $plot - $hu }}" width="{{ $bw }}" height="{{ $hu }}" rx="2"/>
                </g>
                @if ($i % $labelEvery === 0)
                    <text x="{{ $x + $bw / 2 }}" y="{{ $H - 6 }}" text-anchor="middle">{{ \Illuminate\Support\Carbon::parse($b['day'])->format('d.m') }}</text>
                @endif
            @endforeach
            <text x="4" y="{{ $top + 11 }}">{{ $max }}</text>
        </svg>
        <div class="v-legend"><span><i style="background:var(--brand)"></i>{{ __('Visitors') }}</span><span><i style="background:color-mix(in srgb, var(--brand) 22%, transparent)"></i>{{ __('Page views') }}</span><span>{{ __('Hover a bar for the numbers.') }}</span></div>
    </div>

    <div class="panel-grid">
        <div class="card v-card">
            <h2 style="padding:18px 20px 0">{{ __('Most read articles') }}</h2>
            @if ($articles->isEmpty())
                <div class="empty"><x-icon name="eye"/><p>{{ __('No article visits counted yet.') }}</p></div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Article') }}</th><th class="num">{{ __('Views') }}</th><th class="num">{{ __('Downloads') }}</th></tr></thead>
                        <tbody>
                        @foreach ($articles as $a)
                            <tr>
                                <td class="title-cell"><a href="{{ lroute('maqola', $a['id']) }}" target="_blank" rel="noopener" style="color:inherit">{{ \Illuminate\Support\Str::limit($a['title'], 90) }}</a><small>#{{ $a['id'] }}</small></td>
                                <td class="num">{{ $nf($a['views']) }}</td>
                                <td class="num">{{ $nf($a['downloads']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="v-side">
            @foreach ([
                [__('Where visitors came from'), $sources, null],
                [__('Where PDF downloads came from'), $downloadSources, null],
                [__('Pages'), $pages, $pageNames],
                [__('Website language'), $langs, $langNames],
                [__('Device'), $devices, $deviceNames],
            ] as [$heading, $list, $names])
                <div class="card card-pad">
                    <h2>{{ $heading }}</h2>
                    @if (! $list)
                        <p class="muted small" style="margin:0">{{ __('No data yet.') }}</p>
                    @else
                        @php $top1 = max($list); $all = array_sum($list); @endphp
                        <ul class="v-list">
                            @foreach ($list as $item => $hits)
                                <li>
                                    <span>{{ $names[$item] ?? ($item === 'Direct' ? __('Direct (typed the address, bookmark, app)') : ($item === 'Journal page' ? __('From a page of this journal') : $item)) }}</span>
                                    <b>{{ $nf($hits) }} <span class="muted small">{{ round($hits * 100 / max(1, $all)) }}%</span></b>
                                    <span class="track"><span style="width:{{ round($hits * 100 / max(1, $top1)) }}%"></span></span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach

            <div class="card card-pad">
                <h2>{{ __('Search engines and bots') }}</h2>
                <p class="muted small" style="margin:-6px 0 12px">{{ __('Robots that read the site (not counted as visitors). Frequent Googlebot and Yandex visits help the articles appear in search.') }}</p>
                @if (! $bots)
                    <p class="muted small" style="margin:0">{{ __('No data yet.') }}</p>
                @else
                    @php $top1 = max($bots); @endphp
                    <ul class="v-list">
                        @foreach ($bots as $item => $hits)
                            <li>
                                <span>{{ $item === 'Other bots' ? __('Other bots') : $item }}</span>
                                <b>{{ $nf($hits) }}</b>
                                <span class="track"><span style="width:{{ round($hits * 100 / max(1, $top1)) }}%;background:var(--faint)"></span></span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    <p class="muted small" style="margin-top:18px">{{ __('A visitor is counted once a day per browser. IP addresses are not stored. Visits by logged-in editors are not counted.') }}</p>
@endif
@endsection
