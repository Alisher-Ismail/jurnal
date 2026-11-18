@extends('layouts.site')

@section('title', strip_tags($heading))
@if (! empty($canonical))
    @section('canonical', $canonical)
@endif
@if (! empty($noindex))
    @push('meta')
        <meta name="robots" content="noindex, follow">
    @endpush
@endif

@section('content')
<header class="page-head">
    <div class="wrap">
        <nav class="crumbs"><a href="{{ lroute('maqolabosh') }}">{{ __('Home') }}</a><span>{{ $eyebrow }}</span></nav>
        <span class="eyebrow">{{ $eyebrow }}</span>
        <h1>{{ $heading }}</h1>
        <p>{{ trans_choice(':count paper|:count papers', $articles->total()) }}</p>
        <form action="{{ lroute('maqolaqidiruv') }}" method="get" style="display:flex;gap:10px;max-width:560px;margin-top:22px">
            <input class="input" type="search" name="search" value="{{ $query ?? '' }}" placeholder="{{ __('Search papers, authors…') }}" aria-label="{{ __('Search') }}">
            <button class="btn btn-primary" type="submit"><x-icon name="search"/> {{ __('Search') }}</button>
        </form>
    </div>
</header>

<section class="section-tight" style="padding-top:36px">
    <div class="wrap">
        @if ($articles->isEmpty())
            <div class="card empty">
                <x-icon name="search"/>
                <h3>{{ __('Nothing found') }}</h3>
                <p>{{ __('Try another title, author name or keyword.') }}</p>
            </div>
        @else
            <div class="card card-pad">
                <div class="article-list">
                    @foreach ($articles as $article)
                        @include('site.partials.article-item', ['article' => $article, 'showIssue' => true])
                    @endforeach
                </div>
            </div>
            {{ $articles->links() }}
        @endif
    </div>
</section>
@endsection
