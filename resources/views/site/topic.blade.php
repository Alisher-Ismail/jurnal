@extends('layouts.site')

@php
    $count = $topic->articles_count;
    $journal = config('journal.name');
    $authors = $articles->getCollection()->flatMap(fn ($a) => $a->authorList())->unique()->take(4)->implode(', ');
@endphp

@section('title', $topic->name)
@section('document_title', __(':topic: scientific articles (:count) — :journal', ['topic' => $topic->name, 'count' => $count, 'journal' => $journal]))
@section('description', __('Scientific articles on «:topic»: :count open-access papers with full-text PDF and DOI, published in :journal.', ['topic' => $topic->name, 'count' => $count, 'journal' => $journal]).($authors ? ' '.__('Authors').': '.$authors.'.' : ''))
@section('canonical', $canonical)

@push('meta')
    @if ($noindex)
        <meta name="robots" content="noindex, follow">
    @endif
    @php
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => __(':topic: scientific articles', ['topic' => $topic->name]),
            'url' => $canonical,
            'about' => ['@type' => 'Thing', 'name' => $topic->name],
            'isPartOf' => ['@type' => 'WebSite', 'name' => $journal, 'url' => lroute('maqolabosh')],
            'hasPart' => $articles->getCollection()->map(fn ($a) => array_filter([
                '@type' => 'ScholarlyArticle',
                'name' => $a->title,
                'url' => \App\Support\Locale::route('maqola', $a->id, 'uz'),
                'sameAs' => $a->doi ? 'https://doi.org/'.$a->doi : null,
            ]))->values()->all(),
        ];
        $crumbs = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => __('Home'), 'item' => lroute('maqolabosh')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => __('Topics'), 'item' => lroute('mavzular')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $topic->name, 'item' => $canonical],
            ],
        ];
    @endphp
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    <script type="application/ld+json">{!! json_encode($crumbs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
<header class="page-head">
    <div class="wrap">
        <nav class="crumbs"><a href="{{ lroute('maqolabosh') }}">{{ __('Home') }}</a><a href="{{ lroute('mavzular') }}">{{ __('Topics') }}</a><span>{{ $topic->name }}</span></nav>
        <span class="eyebrow">{{ __('Topic') }}</span>
        <h1>{{ $topic->name }}</h1>
        <p>{{ __(':count scientific articles on «:topic» — full text in PDF, with DOI, free to read and cite.', ['count' => $count, 'topic' => $topic->name]) }}</p>
    </div>
</header>

<section class="section-tight" style="padding-top:36px">
    <div class="wrap" style="display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:24px;align-items:start" data-topic-layout>
        <div>
            <div class="card card-pad">
                <div class="article-list">
                    @foreach ($articles as $article)
                        @include('site.partials.article-item', ['article' => $article, 'showIssue' => true])
                    @endforeach
                </div>
            </div>
            {{ $articles->links() }}
        </div>

        <aside style="display:grid;gap:18px">
            <div class="card card-pad">
                <h2 style="font-size:17px;margin:0 0 8px">{{ __('Publish your article on this topic') }}</h2>
                <p class="muted small" style="margin:0 0 14px">{{ __('Writing about «:topic»? Send your paper online or through the Telegram bot: open access, a DOI and indexing in Google Scholar.', ['topic' => $topic->name]) }}</p>
                <a class="btn btn-primary" style="width:100%;justify-content:center" href="{{ lroute('submit') }}"><x-icon name="upload"/> {{ __('Submit a paper') }}</a>
            </div>
            @if ($related->isNotEmpty())
                <div class="card card-pad">
                    <h2 style="font-size:17px;margin:0 0 12px">{{ __('Related topics') }}</h2>
                    <div class="tags">
                        @foreach ($related as $r)
                            <a class="tag" href="{{ lroute('mavzu', $r->slug) }}">{{ $r->name }} <span style="opacity:.6;margin-left:5px">{{ $r->articles_count }}</span></a>
                        @endforeach
                    </div>
                    <p class="small" style="margin:14px 0 0"><a href="{{ lroute('mavzular') }}">{{ __('All topics') }} →</a></p>
                </div>
            @endif
        </aside>
    </div>
</section>
<style>@media (max-width: 900px) { [data-topic-layout] { grid-template-columns: 1fr !important; } }</style>
@endsection
