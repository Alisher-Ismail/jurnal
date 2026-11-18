@extends('layouts.site')

@php
    $authors = $article->authorList();
    $keywords = $article->keywordList();
    $published = $article->onlineAt();
    $pdfUrl = $article->pdfUrl();
    $citation = implode(', ', $authors).' ('.optional($published)->format('Y').'). '.$article->title.'. '
        .config('journal.name').($article->issue ? ', '.$article->issue->volume : '').'. '.($article->doiUrl() ?: lroute('maqola', $article->id));
    // Reader numbers: this site + the Zenodo copy; citations from DataCite / OpenAlex (refreshed weekly by cron).
    $views = (int) $article->views_count + (int) $article->zenodo_views;
    $downloads = (int) $article->downloads_count + (int) $article->zenodo_downloads;
    $citations = (int) $article->citations;
    $scholarUrl = 'https://scholar.google.com/scholar?q='.urlencode('"'.$article->title.'"');
@endphp

@section('title', $article->title)
@section('document_title', $article->title.' — '.__('scientific article').' | '.config('journal.name'))
@section('description', \Illuminate\Support\Str::limit($article->abstract ?: ($article->excerpt ?: $article->title), 280))
@section('og_type', 'article')
{{-- One main address per article (the Uzbek URL), so Scholar does not index three copies. --}}
@section('canonical', \App\Support\Locale::route('maqola', $article->id, 'uz'))

@push('meta')
    {{-- Highwire Press tags read by Google Scholar. --}}
    <meta name="citation_title" content="{{ $article->title }}">
    @foreach ($authors as $name)
        <meta name="citation_author" content="{{ $name }}">
    @endforeach
    @if ($published)
        <meta name="citation_publication_date" content="{{ $published->format('Y/m/d') }}">
        <meta name="citation_online_date" content="{{ $published->format('Y/m/d') }}">
    @endif
    <meta name="citation_journal_title" content="{{ config('journal.name') }}">
    <meta name="citation_publisher" content="{{ config('journal.publisher') }}">
    @if ($issn = \App\Support\JournalProfile::issnNumber())
        <meta name="citation_issn" content="{{ $issn }}">
    @endif
    @if ($article->issue)
        {{-- "Vol. 1 No. 2 (2024)" → volume 1, issue 2, as Google Scholar expects plain numbers. --}}
        @if (preg_match('/Vol\.?\s*(\d+)(?:\s*No\.?\s*(\d+))?/i', (string) $article->issue->volume, $vol))
            <meta name="citation_volume" content="{{ $vol[1] }}">
            @if (! empty($vol[2]))
                <meta name="citation_issue" content="{{ $vol[2] }}">
            @endif
        @else
            <meta name="citation_volume" content="{{ $article->issue->volume }}">
        @endif
        @if ($article->issue->isProceedings())
            <meta name="citation_conference_title" content="{{ $article->issue->title }}">
        @endif
    @endif
    @if ($keywords)
        <meta name="citation_keywords" content="{{ implode('; ', $keywords) }}">
    @endif
    @if ($article->abstract)
        <meta name="citation_abstract" content="{{ \Illuminate\Support\Str::limit($article->abstract, 1000) }}">
    @endif
    <meta name="citation_language" content="{{ $article->languageCode() }}">
    @if ($article->doi)
        <meta name="citation_doi" content="{{ $article->doi }}">
        <meta name="DC.identifier" content="https://doi.org/{{ $article->doi }}">
    @endif
    <link rel="license" href="{{ config('journal.license_url') }}">
    <meta name="DC.rights" content="{{ config('journal.license_url') }}">
    <meta name="citation_abstract_html_url" content="{{ lroute('maqola', $article->id) }}">
    @if ($pdfUrl)
        <meta name="citation_pdf_url" content="{{ $pdfUrl }}">
    @endif
    @php
        // schema.org description of the article for Google (structured data).
        $articleUrl = \App\Support\Locale::route('maqola', $article->id, 'uz');
        $home = \App\Support\Locale::route('maqolabosh', [], 'uz');
        $partOf = ['@type' => 'Periodical', '@id' => $home.'#journal', 'name' => config('journal.name'), 'url' => $home];
        if ($issn = \App\Support\JournalProfile::issnNumber()) {
            $partOf['issn'] = $issn;
        }
        if ($article->issue && preg_match('/Vol\.?\s*(\d+)(?:\s*No\.?\s*(\d+))?/i', (string) $article->issue->volume, $v)) {
            $partOf = ['@type' => 'PublicationVolume', 'volumeNumber' => $v[1], 'isPartOf' => $partOf];
            if (! empty($v[2])) {
                $partOf = ['@type' => 'PublicationIssue', 'issueNumber' => $v[2], 'datePublished' => optional($published)->format('Y-m-d'), 'isPartOf' => $partOf];
            }
        }
        $schema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'ScholarlyArticle',
            '@id' => $articleUrl,
            'headline' => \Illuminate\Support\Str::limit($article->title, 110, '…'),
            'name' => $article->title,
            'author' => array_map(fn ($name) => ['@type' => 'Person', 'name' => $name], $authors),
            'datePublished' => optional($published)->format('Y-m-d'),
            'inLanguage' => $article->languageCode(),
            'keywords' => $keywords ? implode(', ', $keywords) : null,
            'abstract' => $article->abstract ? \Illuminate\Support\Str::limit($article->abstract, 2000) : null,
            'description' => ! $article->abstract && $article->excerpt ? \Illuminate\Support\Str::limit($article->excerpt, 500) : null,
            'url' => $articleUrl,
            'mainEntityOfPage' => $articleUrl,
            'sameAs' => $article->doiUrl(),
            'identifier' => $article->doi ? ['@type' => 'PropertyValue', 'propertyID' => 'DOI', 'value' => $article->doi] : null,
            'isAccessibleForFree' => true,
            'isPartOf' => $partOf,
            'publisher' => ['@type' => 'Organization', 'name' => config('journal.publisher')],
            'encoding' => $pdfUrl ? ['@type' => 'MediaObject', 'contentUrl' => $pdfUrl, 'encodingFormat' => 'application/pdf'] : null,
            'interactionStatistic' => [
                ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/ViewAction', 'userInteractionCount' => $views],
                ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/DownloadAction', 'userInteractionCount' => $downloads],
            ],
        ]);
    @endphp
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
<header class="page-head">
    <div class="wrap">
        <nav class="crumbs">
            <a href="{{ lroute('maqolabosh') }}">{{ __('Home') }}</a>
            <a href="{{ lroute('mvolume') }}">{{ __('Archive') }}</a>
            @if ($article->issue)
                <a href="{{ lroute('maqolavolume', $article->issue->id) }}">{{ \Illuminate\Support\Str::limit($article->issue->title, 50) }}</a>
            @endif
        </nav>
        <span class="eyebrow">{{ __('Research article') }}</span>
        <h1 class="article-title">{{ $article->title }}</h1>
        @if ($authors)
            <div class="article-authors">
                @foreach ($authors as $name)
                    <a class="author-chip" href="{{ lroute('maqolaauthor', $name) }}"><i>{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</i>{{ $name }}</a>
                @endforeach
            </div>
        @endif
        @if ($article->doi)
            <p class="article-doi" style="margin:14px 0 0;font-size:14.5px">DOI: <a href="{{ $article->doiUrl() }}" target="_blank" rel="noopener">{{ $article->doiUrl() }}</a></p>
        @endif
        <div class="article-metrics" style="display:flex;flex-wrap:wrap;gap:8px 22px;margin-top:16px;font-size:14px;color:var(--muted)">
            <span title="{{ __('On this website: :site · on Zenodo: :zenodo', ['site' => (int) $article->views_count, 'zenodo' => (int) $article->zenodo_views]) }}"><x-icon name="eye" style="width:16px;height:16px;vertical-align:-3px"/> <b style="color:var(--ink)">{{ number_format($views, 0, '.', ' ') }}</b> {{ __('views') }}</span>
            <span title="{{ __('On this website: :site · on Zenodo: :zenodo', ['site' => (int) $article->downloads_count, 'zenodo' => (int) $article->zenodo_downloads]) }}"><x-icon name="download" style="width:16px;height:16px;vertical-align:-3px"/> <b style="color:var(--ink)">{{ number_format($downloads, 0, '.', ' ') }}</b> {{ __('downloads') }}</span>
            <span title="{{ __('Citations found by DataCite and OpenAlex') }}"><x-icon name="book" style="width:16px;height:16px;vertical-align:-3px"/> <b style="color:var(--ink)">{{ number_format($citations, 0, '.', ' ') }}</b> {{ __('citations') }}
                · <a href="{{ $scholarUrl }}" target="_blank" rel="noopener nofollow">Google Scholar</a></span>
        </div>
    </div>
</header>

<section class="section-tight" style="padding-top:44px">
    <div class="wrap article-layout">
        <div>
            <div class="card card-pad abstract">
                @if ($article->abstract)
                    <h2>{{ __('Abstract') }}</h2>
                    <p>{{ $article->abstract }}</p>
                @elseif ($article->excerpt)
                    {{-- Papers without an abstract: the opening of the text (Google Scholar accepts it instead). --}}
                    <h2>{{ __('From the paper') }}</h2>
                    <p>{{ $article->excerpt }}</p>
                    @if ($pdfUrl)
                        <p style="margin-top:10px"><a href="{{ $pdfUrl }}" target="_blank" rel="noopener">{{ __('Read the full text (PDF)') }} →</a></p>
                    @endif
                @else
                    <h2>{{ __('Abstract') }}</h2>
                    <p class="muted">{{ __('The abstract is available in the full text.') }}</p>
                @endif

                @if ($keywords)
                    <h2 style="margin-top:28px">{{ __('Keywords') }}</h2>
                    <div class="tags">
                        @foreach ($keywords as $keyword)
                            @php $topicSlug = \App\Services\TopicIndex::slug($keyword); @endphp
                            <a class="tag" href="{{ $topicSlug !== '' ? lroute('mavzu', $topicSlug) : lroute('maqolaqidiruv').'?search='.urlencode($keyword) }}">{{ $keyword }}</a>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($related->isNotEmpty())
                <div class="card card-pad" style="margin-top:20px">
                    <h2 style="font-size:20px;margin-bottom:4px">{{ __('More from this issue') }}</h2>
                    <div class="article-list">
                        @foreach ($related as $item)
                            @include('site.partials.article-item', ['article' => $item])
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <aside>
            <div class="card side-box">
                @if ($pdfUrl)
                    <a href="{{ $pdfUrl }}" class="btn btn-primary" target="_blank" rel="noopener"><x-icon name="download"/> {{ __('Download PDF') }}</a>
                @else
                    <p class="muted small" style="margin:0">{{ __('The full text will be available soon.') }}</p>
                @endif
            </div>
            <div class="card side-box">
                <h4>{{ __('Details') }}</h4>
                <dl>
                    @if ($article->issue)
                        <div><dt>{{ __('Issue') }}</dt><dd><a href="{{ lroute('maqolavolume', $article->issue->id) }}">{{ $article->issue->volume }}: {{ $article->issue->title }}</a></dd></div>
                    @endif
                    @if ($published)
                        <div><dt>{{ __('Published') }}</dt><dd>{{ $published->format('d.m.Y') }}</dd></div>
                    @endif
                    @if ($article->doi)
                        <div><dt>DOI</dt><dd><a href="{{ $article->doiUrl() }}" target="_blank" rel="noopener">{{ $article->doi }}</a></dd></div>
                    @endif
                    <div><dt>{{ __('License') }}</dt><dd>{{ __('Open access') }} · <a href="{{ config('journal.license_url') }}" rel="license noopener" target="_blank">{{ config('journal.license') }}</a></dd></div>
                </dl>
            </div>
            <div class="card side-box">
                <h4>{{ __('How to cite') }}</h4>
                <div class="cite" id="cite">{{ $citation }}</div>
                <button type="button" class="btn btn-ghost btn-sm copy-btn" data-copy="#cite"><x-icon name="copy"/> <span>{{ __('Copy') }}</span></button>
            </div>

            @include('site.partials.channel-box')
        </aside>
    </div>
</section>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const text = document.querySelector(btn.dataset.copy).innerText;
            navigator.clipboard?.writeText(text).then(function () {
                btn.querySelector('span').textContent = @json(__('Copied'));
            });
        });
    });
</script>
@endpush
