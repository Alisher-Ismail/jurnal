{{-- Originality report: archive similarity, web matches and the AI-writing indicator. Expects $paper. --}}
@php
    $check = $paper->check;
    $levelClass = ['low' => 'badge-accepted', 'medium' => 'badge-under_review', 'high' => 'badge-rejected'];
    $sourceUrl = function ($s) {
        return match ($s['type']) {
            'article' => lroute('maqola', $s['ref_id']),
            'issue' => lroute('maqolavolume', $s['ref_id']),
            'external' => optional(\App\Models\ExternalWork::find($s['ref_id']))->link() ?: '#',
            default => \App\Models\Paper::find($s['ref_id']) ? route('admin.papers.show', \App\Models\Paper::find($s['ref_id'])) : '#',
        };
    };
    $typeLabel = ['article' => __('Article'), 'issue' => __('Issue'), 'paper' => __('Submission'), 'external' => __('Published elsewhere')];
@endphp
<div class="card card-pad panel">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <h2 style="margin:0">🔎 {{ __('Originality check') }}</h2>
        <form method="post" action="{{ route('admin.papers.check', $paper) }}">
            @csrf
            <button class="btn btn-ghost btn-sm" data-busy="{{ __('Checking… this can take a minute') }}">{{ $check ? __('Run again') : __('Run check') }}</button>
        </form>
    </div>

    {{-- Authorship verdict first: it is the main question for the editor. --}}
    @if ($check && $check->conflictType() === 'mismatch')
        <div class="alert alert-bad" style="display:block">
            <strong>⚠️ {{ __('Authorship conflict: this text matches work of other authors.') }}</strong>
            @foreach ($check->conflict['items'] as $item)
                @continue($item['overlap'])
                <div style="margin-top:8px;font-size:14px;color:var(--text)">
                    <strong>{{ $item['percent'] }}%</strong> · <a href="{{ $sourceUrl($item) }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($item['title'], 100) }}</a><br>
                    {{ __('Submitted authors') }}: <strong>{{ $paper->authors }}</strong><br>
                    @if ($item['source_authors'])
                        {{ __('Authors of the matching text') }}: <strong>{{ $item['source_authors'] }}</strong>
                    @elseif ($item['context'])
                        {{ __('Printed before the matching text in the volume') }}: <em>{{ \Illuminate\Support\Str::limit($item['context'], 300) }}</em>
                    @endif
                </div>
            @endforeach
        </div>
    @elseif ($check && $check->conflictType() === 'web')
        <div class="alert alert-bad"><x-icon name="alert"/><span><strong>{{ __('Most checked sentences were found on the web.') }}</strong> {{ __('Open the links below and compare the authors.') }}</span></div>
    @elseif ($check && $check->conflictType() === 'same_authors')
        <div class="alert alert-info"><x-icon name="info"/><span>{{ __('Matches an earlier text by the same authors.') }} {{ __('Check that it is not a duplicate publication.') }}</span></div>
    @endif

    @if ($check && $check->certificate_held && ! \App\Models\Paper::certificateNeedsPayment())
        <form method="post" action="{{ route('admin.papers.release', $paper) }}" class="alert alert-info" style="align-items:center;justify-content:space-between;flex-wrap:wrap" data-confirm="{{ __('Issue the submission certificate to this author?') }}">
            @csrf
            <span>⏸ {{ __('Certificate is held until an editor releases it.') }}</span>
            <button class="btn btn-primary btn-sm">{{ __('Verified — issue certificate') }}</button>
        </form>
    @endif

    @if (! $check)
        <p class="muted small" style="margin:0">{{ __('Not checked yet. New submissions are checked automatically a minute after they arrive.') }}</p>
    @elseif ($check->status === 'running')
        <p class="muted small" style="margin:0">{{ __('The check is running. Reload the page in a minute.') }}</p>
    @elseif ($check->status === 'failed')
        <div class="alert alert-bad" style="margin:0"><x-icon name="alert"/><span>{{ $check->error }}</span></div>
    @else
        <div class="grid-3" style="gap:12px;margin-bottom:18px">
            <div class="card" style="padding:14px 16px;box-shadow:none">
                <div class="muted small">{{ __('Archive similarity') }}</div>
                <div style="font:600 26px var(--serif);color:var(--ink)">{{ $check->similarity }}%</div>
                <span class="badge {{ $levelClass[$check->similarityLevel()] }}">{{ __('level.'.$check->similarityLevel()) }}</span>
            </div>
            <div class="card" style="padding:14px 16px;box-shadow:none">
                <div class="muted small">{{ __('Found on the web') }}</div>
                @if ($check->web_checked)
                    <div style="font:600 26px var(--serif);color:var(--ink)">{{ $check->web_found }} / {{ $check->web_checked }}</div>
                    <span class="muted small">{{ __('sentences') }}</span>
                @else
                    <div style="font:600 20px var(--serif);color:var(--faint)">—</div>
                    <span class="muted small">{{ $check->web_note === 'monthly_limit' ? __('Monthly search limit reached') : ($check->web_note === 'not_configured' ? __('Web search is not configured') : __('Web search unavailable')) }}</span>
                @endif
            </div>
            <div class="card" style="padding:14px 16px;box-shadow:none">
                <div class="muted small">{{ __('AI-writing indicator') }}</div>
                @if ($check->ai_score === null)
                    <div style="font:600 20px var(--serif);color:var(--faint)">—</div>
                    <span class="muted small">{{ __('Text too short to judge') }}</span>
                @else
                    <div style="font:600 26px var(--serif);color:var(--ink)">{{ $check->ai_score }}<span class="muted" style="font-size:15px"> / 100</span></div>
                    <span class="badge {{ $levelClass[$check->aiLevel()] }}">{{ __('level.'.$check->aiLevel()) }}</span>
                @endif
            </div>
        </div>

        @if ($check->sources)
            <h3 style="font:600 14px var(--sans);margin:0 0 8px">{{ __('Matching sources in the archive') }}</h3>
            <div style="display:grid;gap:6px;margin-bottom:18px">
                @foreach ($check->sources as $s)
                    <div style="display:grid;grid-template-columns:52px 1fr;gap:10px;align-items:center;font-size:14px">
                        <strong style="text-align:right" title="{{ $s['type'] === 'external' ? __('Share of the published abstract found in the submission') : '' }}">{{ $s['type'] === 'external' ? round(($s['coverage'] ?? 0) * 100).'%' : $s['percent'].'%' }}</strong>
                        <span><span class="tag" style="height:22px;font-size:11.5px">{{ $typeLabel[$s['type']] ?? $s['type'] }}</span>
                            <a href="{{ $sourceUrl($s) }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($s['title'], 110) }}</a>
                            @if (! empty($s['authors']))<br><span class="muted small">{{ \Illuminate\Support\Str::limit($s['authors'], 120) }}</span>@endif</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($check->passages)
            <details style="margin-bottom:16px">
                <summary style="cursor:pointer;font-weight:600;font-size:14px">{{ __('Matching passages') }} ({{ count($check->passages) }})</summary>
                <div style="display:grid;gap:10px;margin-top:10px">
                    @foreach ($check->passages as $p)
                        <div class="cite" style="font-size:13.5px">
                            <mark style="background:var(--warn-soft);color:inherit">{{ $p['text'] }}</mark>
                            <div class="muted" style="font-size:12px;margin-top:4px">{{ $p['words'] }} {{ __('words') }} · {{ \Illuminate\Support\Str::limit($p['title'] ?? '', 90) }}</div>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif

        @if ($check->web_matches)
            <details style="margin-bottom:16px" open>
                <summary style="cursor:pointer;font-weight:600;font-size:14px">{{ __('Sentences found on the web') }} ({{ count($check->web_matches) }})</summary>
                <div style="display:grid;gap:10px;margin-top:10px">
                    @foreach ($check->web_matches as $m)
                        <div class="cite" style="font-size:13.5px">
                            «{{ \Illuminate\Support\Str::limit($m['sentence'], 260) }}»
                            @foreach ($m['results'] as $r)
                                <div style="margin-top:4px"><a href="{{ $r['url'] }}" target="_blank" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit($r['title'] ?: $r['url'], 90) }}</a></div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </details>
        @endif

        @if ($check->ai_signals)
            <details>
                <summary style="cursor:pointer;font-weight:600;font-size:14px">{{ __('Why this AI indicator?') }}</summary>
                <div style="display:grid;gap:8px;margin-top:10px">
                    @foreach ($check->ai_signals as $s)
                        <div style="display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;font-size:13.5px">
                            <span>{{ __($s['label']) }} <span class="muted">· {{ $s['display'] }}{{ $s['key'] === 'phrases' ? ' '.__('words') : '' }}</span>
                                @if (! empty($s['examples']))<br><span class="muted" style="font-size:12px">{{ implode(', ', $s['examples']) }}</span>@endif
                            </span>
                            <span style="width:90px;height:8px;border-radius:99px;background:var(--surface-2);overflow:hidden;display:block"><span style="display:block;height:100%;width:{{ round($s['level'] * 100) }}%;background:{{ $s['level'] > 0.6 ? 'var(--bad)' : ($s['level'] > 0.3 ? 'var(--warn)' : 'var(--ok)') }}"></span></span>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif

        <p class="muted" style="font-size:12px;margin:14px 0 0">
            {{ __('Checked') }} {{ optional($check->checked_at)->format('d.m.Y H:i') }} · {{ $check->words }} {{ __('words') }}.
            {{ __('Similarity compares 5-word sequences with every issue, article and earlier submission. The AI indicator is a statistical hint, never proof: read the text before deciding.') }}
        </p>
    @endif

    {{-- antiplag.uz has no API: the editor checks there manually and records the result here. --}}
    <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--line)">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
            <h3 style="font:600 14px var(--sans);margin:0">{{ __('External check (antiplag.uz)') }}</h3>
            <span class="btn-row" style="gap:6px">
                @if ($paper->hasFile())<a class="btn btn-ghost btn-sm" href="{{ route('admin.papers.file', $paper) }}"><x-icon name="download"/> {{ __('Manuscript') }}</a>@endif
                <a class="btn btn-ghost btn-sm" href="https://antiplag.uz/" target="_blank" rel="noopener"><x-icon name="external"/> antiplag.uz</a>
            </span>
        </div>
        @if ($check && $check->external_at)
            <div class="cite" style="font-size:14px;margin-bottom:10px">
                <strong>{{ $check->external_service }}</strong>:
                {{ __('similarity') }} <strong>{{ $check->external_similarity !== null ? $check->external_similarity.'%' : '—' }}</strong>
                · AI <strong>{{ $check->external_ai !== null ? $check->external_ai.'%' : '—' }}</strong>
                @if ($check->external_report) · <a href="{{ route('admin.papers.external.report', $paper) }}" target="_blank" rel="noopener">{{ __('report') }}</a>@endif
                @if ($check->external_note)<br><span class="muted">{{ $check->external_note }}</span>@endif
                <div class="muted" style="font-size:12px;margin-top:4px">{{ $check->external_by }} · {{ $check->external_at->format('d.m.Y H:i') }}</div>
            </div>
        @endif
        <details @if (! ($check && $check->external_at)) open @endif>
            <summary style="cursor:pointer;font-size:13.5px;color:var(--muted)">{{ ($check && $check->external_at) ? __('Update result') : __('Record the result') }}</summary>
            <form method="post" action="{{ route('admin.papers.external', $paper) }}" enctype="multipart/form-data" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:10px">
                @csrf
                <div class="field"><label>{{ __('Service') }}</label><input class="input" name="external_service" value="{{ optional($check)->external_service ?: 'antiplag.uz' }}" required maxlength="60"></div>
                <div class="field"><label>{{ __('Similarity') }}, %</label><input class="input" type="number" step="0.1" min="0" max="100" name="external_similarity" value="{{ optional($check)->external_similarity }}"></div>
                <div class="field"><label>AI, %</label><input class="input" type="number" step="0.1" min="0" max="100" name="external_ai" value="{{ optional($check)->external_ai }}"></div>
                <div class="field" style="grid-column:1/3"><label>{{ __('Note') }} <span class="opt">{{ __('optional') }}</span></label><input class="input" name="external_note" maxlength="1000" value="{{ optional($check)->external_note }}"></div>
                <div class="field"><label>{{ __('Report (PDF/image)') }}</label><input class="input" type="file" name="report" accept="application/pdf,image/*"></div>
                <div style="grid-column:1/-1"><button class="btn btn-ghost btn-sm"><x-icon name="check"/> {{ __('Save') }}</button></div>
            </form>
        </details>
    </div>
</div>
