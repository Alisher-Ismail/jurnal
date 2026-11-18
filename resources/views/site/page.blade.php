@extends('layouts.site')

@section('title', $title)
@section('description', \Illuminate\Support\Str::limit(trim(html_entity_decode(strip_tags((string) $html))), 280))

@section('content')
<header class="page-head">
    <div class="wrap">
        <nav class="crumbs"><a href="{{ lroute('maqolabosh') }}">{{ __('Home') }}</a><span>{{ __('Journal') }}</span></nav>
        <h1>{{ $title }}</h1>
    </div>
</header>

<section class="section-tight" style="padding-top:44px">
    <div class="wrap prose-layout">
        <nav class="side-nav" aria-label="{{ __('Journal') }}">
            @foreach (\App\Support\PageContent::PAGES as $pageKey => $page)
                @continue($pageKey === 'submission')
                <a href="{{ lroute($page['route']) }}" @class(['active' => $pageKey === $key])>{{ __($page['title']) }}</a>
            @endforeach
            <a href="{{ lroute('submit') }}">{{ __('Submit a paper') }}</a>
        </nav>
        <div class="card card-pad">
            @if (filled(strip_tags((string) $html)))
                {{-- Authored by editors in the admin panel. --}}
                <div class="prose">{!! $html !!}</div>
            @else
                <div class="empty"><x-icon name="file"/><p>{{ __('This page is being prepared.') }}</p></div>
            @endif
        </div>
    </div>
</section>
@endsection
