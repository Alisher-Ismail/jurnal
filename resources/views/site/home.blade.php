@extends('layouts.site')

@php
    $bot = config('services.telegram.username');
    $botUrl = $bot ? 'https://t.me/'.ltrim($bot, '@') : null;
@endphp

@section('document_title', config('journal.name').' — '.__('scientific journal: online article submission and publication, DOI'))
@section('description', __('Emro Science and Education is an open-access scientific journal: publish your paper online or via Telegram, with a DOI from Zenodo, indexed in Google Scholar, OpenAIRE and DataCite.'))

@push('meta')
    @php
        $home = \App\Support\Locale::route('maqolabosh', [], 'uz');
        $periodical = array_filter([
            '@type' => 'Periodical',
            '@id' => $home.'#journal',
            'name' => config('journal.name'),
            'alternateName' => config('journal.short_name'),
            'url' => $home,
            'issn' => \App\Support\JournalProfile::issnNumber(),
            'inLanguage' => ['uz', 'ru', 'en'],
            'isAccessibleForFree' => true,
            'publisher' => ['@type' => 'Organization', 'name' => config('journal.publisher')],
            'sameAs' => array_values(array_filter([config('journal.telegram_channel')])),
        ]);
        $schema = ['@context' => 'https://schema.org', '@graph' => [
            $periodical,
            [
                '@type' => 'WebSite',
                '@id' => $home.'#website',
                'name' => config('journal.name'),
                'url' => $home,
                'publisher' => ['@id' => $home.'#journal'],
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => \App\Support\Locale::route('maqolaqidiruv', [], 'uz').'?search={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ]];
    @endphp
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
<section class="hero">
    <div class="wrap hero-grid">
        <div>
            <span class="eyebrow"><x-icon name="open" style="width:16px;height:16px"/> {{ __('Open-access scientific journal') }}</span>
            <h1>{!! __('Scientific journal: publish your research. <em>Get certified</em> in minutes.') !!}</h1>
            <p class="lead">{{ __('Submit your paper on the website or straight from Telegram. You instantly receive a submission certificate with a QR code, follow the review online, and get a permanent link once it is published.') }}</p>
            <div class="btn-row">
                <a href="{{ lroute('submit') }}" class="btn btn-primary btn-lg"><x-icon name="upload"/> {{ __('Submit a paper') }}</a>
                @if ($botUrl)
                    <a href="{{ $botUrl }}" class="btn btn-tg btn-lg" target="_blank" rel="noopener"><x-icon name="telegram"/> {{ __('Submit via Telegram') }}</a>
                @else
                    <a href="{{ lroute('mvolume') }}" class="btn btn-ghost btn-lg">{{ __('Browse the archive') }}</a>
                @endif
            </div>
            <div class="hero-meta">
                <span><x-icon name="qr"/> {{ __('Certificate with QR code') }}</span>
                <span><x-icon name="shield"/> {{ __('Plagiarism checked') }}</span>
                <span><x-icon name="globe"/> {{ __('Uzbek · English · Russian') }}</span>
            </div>
        </div>

        <div class="hero-card">
            <span class="label">{{ __('Current issue') }}</span>
            @if ($current)
                <div style="display:grid;grid-template-columns:132px 1fr;gap:20px;align-items:center;margin-top:8px">
                    @include('site.partials.cover', ['issue' => $current])
                    <div>
                        <h3 style="margin-top:0">{{ $current->title }}</h3>
                        <p>{{ $current->volume }}@if ($currentArticles->isNotEmpty()) · {{ trans_choice(':count paper|:count papers', $currentArticles->count()) }}@endif</p>
                    </div>
                </div>
                <div class="btn-row" style="margin-top:18px">
                    <a href="{{ lroute('maqolavolume', $current->id) }}" class="btn btn-ghost btn-sm">{{ __('Open issue') }} <x-icon name="arrow-right"/></a>
                    @if ($current->pdfUrl())
                        <a href="{{ $current->pdfUrl() }}" class="btn btn-ghost btn-sm" target="_blank" rel="noopener"><x-icon name="download"/> PDF</a>
                    @endif
                </div>
            @else
                <h3>{{ __('The first issue is on its way') }}</h3>
                <p>{{ __('Submissions are open. Be among the first authors.') }}</p>
            @endif
            <div class="cert-chip">
                <span class="qr"></span>
                <span><strong>{{ __('Certificate issued') }}</strong>{{ __('Verified by QR code') }}</span>
            </div>
        </div>
    </div>
</section>

<div class="wrap">
    <div class="stats">
        <div class="stat"><b>{{ $stats['issues'] }}</b><span>{{ __('Issues') }}</span></div>
        <div class="stat"><b>{{ $stats['articles'] }}</b><span>{{ __('Published papers') }}</span></div>
        <div class="stat"><b>{{ $stats['authors'] }}</b><span>{{ __('Authors') }}</span></div>
        <div class="stat"><b>3</b><span>{{ __('Languages') }}</span></div>
    </div>
</div>

@if ($current && $currentArticles->isNotEmpty())
<section class="section">
    <div class="wrap">
        <div class="section-head">
            <div>
                <span class="eyebrow">{{ __('Current issue') }}</span>
                <h2>{{ $current->title }}</h2>
                @if ($current->abstract)
                    <p>{{ \Illuminate\Support\Str::limit($current->abstract, 220) }}</p>
                @endif
            </div>
            <a href="{{ lroute('maqolavolume', $current->id) }}" class="btn btn-ghost">{{ __('All papers in this issue') }} <x-icon name="arrow-right"/></a>
        </div>
        <div class="card card-pad">
            <div class="article-list">
                @foreach ($currentArticles as $article)
                    @include('site.partials.article-item', ['article' => $article])
                @endforeach
            </div>
        </div>
    </div>
</section>
@endif

<section class="section" style="background:var(--surface-2);border-block:1px solid var(--line)">
    <div class="wrap">
        <div class="section-head">
            <div>
                <span class="eyebrow">{{ __('How it works') }}</span>
                <h2>{{ __('From manuscript to publication') }}</h2>
                <p>{{ __('A transparent process: you always know where your paper is.') }}</p>
            </div>
        </div>
        <div class="steps">
            <div class="card step">
                <div class="num"><x-icon name="upload"/></div>
                <h3>{{ __('1. Submit') }}</h3>
                <p>{{ __('Fill in a short form on the website or answer a few questions in our Telegram bot and attach the file.') }}</p>
            </div>
            <div class="card step">
                <div class="num"><x-icon name="qr"/></div>
                <h3>{{ __('2. Get a certificate') }}</h3>
                <p>{{ __('A submission certificate with a QR code arrives immediately by e-mail or Telegram.') }}</p>
            </div>
            <div class="card step">
                <div class="num"><x-icon name="eye"/></div>
                <h3>{{ __('3. Review') }}</h3>
                <p>{{ __('Editors check the paper for quality and plagiarism. You are notified at every step.') }}</p>
            </div>
            <div class="card step">
                <div class="num"><x-icon name="award"/></div>
                <h3>{{ __('4. Publication') }}</h3>
                <p>{{ __('Accepted papers get a permanent link in the archive and a publication certificate.') }}</p>
            </div>
        </div>
    </div>
</section>

@if ($botUrl)
<section class="section">
    <div class="wrap">
        <div class="tg-band">
            <div>
                <span class="eyebrow" style="color:#d5ecf8"><x-icon name="telegram" style="width:16px;height:16px"/> Telegram</span>
                <h2>{{ __('The whole journal in your Telegram') }}</h2>
                <p>{{ __('No registration and no e-mail needed. Open the bot and send your paper in a minute.') }}</p>
                <ul>
                    <li><x-icon name="check"/> {{ __('Step-by-step submission in Uzbek, English or Russian') }}</li>
                    <li><x-icon name="check"/> {{ __('Certificate with QR code delivered to the chat') }}</li>
                    <li><x-icon name="check"/> {{ __('Status updates and the link to your published paper') }}</li>
                    <li><x-icon name="check"/> {{ __('Find already published papers and get their certificate') }}</li>
                </ul>
                <a href="{{ $botUrl }}" class="btn btn-light btn-lg" target="_blank" rel="noopener"><x-icon name="telegram"/> {{ __('Open') }} {{ '@'.ltrim($bot, '@') }}</a>
            </div>
            <div class="chat" aria-hidden="true">
                <div class="bubble me">📝 {{ __('Submit a paper') }}</div>
                <div class="bubble bot">{{ __('Send the title of your paper.') }}</div>
                <div class="bubble me file"><i>PDF</i><span>manuscript.pdf<br><small style="color:#5d6779">1.2 MB</small></span></div>
                <div class="bubble bot">✅ {{ __('Your paper has been received') }}<br><small style="color:#5d6779">ES-{{ date('y') }}-7KQ2MX · certificate.pdf</small></div>
            </div>
        </div>
    </div>
</section>
@endif

@if ($latest->isNotEmpty())
<section class="section" style="padding-top:{{ $botUrl ? '0' : '72px' }}">
    <div class="wrap">
        <div class="section-head">
            <div>
                <span class="eyebrow">{{ __('Latest papers') }}</span>
                <h2>{{ __('Recently published') }}</h2>
            </div>
            <a href="{{ lroute('mvolume') }}" class="btn btn-ghost">{{ __('Browse the archive') }} <x-icon name="arrow-right"/></a>
        </div>
        <div class="grid-3">
            @foreach ($latest as $article)
                <article class="card paper-card">
                    @if ($article->issue)
                        <span><a class="tag tag-brand" href="{{ lroute('maqolavolume', $article->issue->id) }}">{{ \Illuminate\Support\Str::limit($article->issue->title, 40) }}</a></span>
                    @endif
                    <h3><a href="{{ lroute('maqola', $article->id) }}">{{ $article->title }}</a></h3>
                    <div class="authors">{{ implode(', ', $article->authorList()) }}</div>
                    <div class="meta-row">
                        <span><x-icon name="calendar"/> {{ optional($article->publishedAt())->format('d.m.Y') }}</span>
                        @if ($article->pdfUrl())
                            <span><x-icon name="file"/> PDF</span>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>
@endif

@php
    $homeTopics = \Illuminate\Support\Facades\Schema::hasTable('topics')
        ? \App\Models\Topic::where('articles_count', '>=', 5)->orderByDesc('articles_count')->limit(30)->get(['slug', 'name', 'articles_count'])
        : collect();
@endphp
<section class="section" style="padding-top:0">
    <div class="wrap">
        <div class="card card-pad">
            <span class="eyebrow">{{ __('Topics') }}</span>
            <h2 style="margin:6px 0 10px">{{ __('Scientific articles by topic') }}</h2>
            <p class="muted" style="max-width:85ch;margin:0 0 16px">{{ __(':journal is an open-access scientific journal from Uzbekistan. It publishes scientific articles, conference abstracts and proceedings in economics, education and pedagogy, philology, engineering, medicine and other fields — in Uzbek, Russian and English. Every article is free to read, downloadable as PDF and has a permanent DOI.', ['journal' => config('journal.name')]) }}</p>
            @if ($homeTopics->isNotEmpty())
                <div class="tags" style="gap:8px">
                    @foreach ($homeTopics as $t)
                        <a class="tag" href="{{ lroute('mavzu', $t->slug) }}">{{ $t->name }} <span style="opacity:.6;margin-left:5px">{{ $t->articles_count }}</span></a>
                    @endforeach
                </div>
            @endif
            <div class="btn-row" style="margin-top:18px">
                <a href="{{ lroute('mavzular') }}" class="btn btn-ghost">{{ __('All topics') }} <x-icon name="arrow-right"/></a>
                <a href="{{ lroute('guide') }}" class="btn btn-ghost">{{ __('How to publish a paper') }} <x-icon name="arrow-right"/></a>
            </div>
        </div>
    </div>
</section>

<section class="section" style="padding-top:0">
    <div class="wrap">
        @include('site.partials.channel-box', ['wide' => true])
    </div>
</section>

@if ($about)
<section class="section" style="padding-top:0">
    <div class="wrap">
        <div class="card card-pad" style="display:grid;grid-template-columns:1fr auto;gap:28px;align-items:center">
            <div>
                <span class="eyebrow">{{ __('About the journal') }}</span>
                <p class="muted" style="font-size:17px;margin:0;max-width:80ch">{{ $about }}</p>
            </div>
            <a href="{{ lroute('haqimiz') }}" class="btn btn-dark">{{ __('Learn more') }}</a>
        </div>
    </div>
</section>
@endif
@endsection
