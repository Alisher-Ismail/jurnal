@extends('layouts.site')

@section('title', $issue->title)
{{-- The article list is the same in every language: the Uzbek page is the main version. --}}
@section('canonical', \App\Support\Locale::route('maqolavolume', $issue->id, 'uz').((int) request('page') > 1 ? '?page='.(int) request('page') : ''))
@section('description', \Illuminate\Support\Str::limit($issue->abstract ?: $issue->title, 280))

@section('content')
<header class="page-head">
    <div class="wrap">
        <nav class="crumbs">
            <a href="{{ lroute('maqolabosh') }}">{{ __('Home') }}</a>
            <a href="{{ lroute('mvolume') }}">{{ __('Archive') }}</a>
            <span>{{ $issue->volume }}</span>
        </nav>
        <span class="eyebrow">{{ $issue->volume }}</span>
        <h1>{{ $issue->title }}</h1>
    </div>
</header>

<section class="section-tight" style="padding-top:44px">
    <div class="wrap issue-feature">
        <div>
            @include('site.partials.cover', ['issue' => $issue])
            <div class="btn-row" style="margin-top:16px">
                @if ($issue->pdfUrl())
                    <a href="{{ $issue->pdfUrl() }}" class="btn btn-dark" style="flex:1" target="_blank" rel="noopener"><x-icon name="download"/> {{ __('Full issue (PDF)') }}</a>
                @endif
            </div>
            <dl class="muted small" style="margin:16px 0 0;display:grid;gap:4px">
                <div>{{ __('Published') }}: <strong style="color:var(--ink)">{{ optional($issue->created_at)->format('d.m.Y') }}</strong></div>
                <div>{{ __('Papers') }}: <strong style="color:var(--ink)">{{ $articles->count() }}</strong></div>
            </dl>
        </div>

        <div>
            @if ($issue->abstract)
                <div class="card card-pad" style="margin-bottom:20px">
                    <p class="muted" style="margin:0;white-space:pre-line">{{ $issue->abstract }}</p>
                </div>
            @endif
            <div class="card card-pad">
                <h2 style="font-size:22px;margin-bottom:6px">{{ __('Table of contents') }}</h2>
                @if ($articles->isEmpty())
                    <div class="empty"><x-icon name="file"/><p>{{ __('Papers of this issue will appear here soon.') }}</p></div>
                @else
                    <div class="article-list">
                        @foreach ($articles as $article)
                            @include('site.partials.article-item', ['article' => $article])
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
@endsection
