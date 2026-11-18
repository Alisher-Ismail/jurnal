@extends('layouts.site')

@section('title', __('Archive'))
@section('document_title', __('Scientific articles archive — all issues').' | '.config('journal.name'))
@section('description', __('All issues and scientific articles of :journal: open access, full-text PDF, DOI. Economics, pedagogy, philology, engineering and natural sciences in Uzbek, Russian and English.', ['journal' => config('journal.name')]))

@section('content')
<header class="page-head">
    <div class="wrap">
        <nav class="crumbs"><a href="{{ lroute('maqolabosh') }}">{{ __('Home') }}</a><span>{{ __('Archive') }}</span></nav>
        <h1>{{ __('Archive') }}</h1>
        <p>{{ __('All issues of the journal. Every paper is open access and free to download.') }}</p>
    </div>
</header>

<section class="section-tight" style="padding-top:44px">
    <div class="wrap">
        @if ($issues->isEmpty())
            <div class="card empty">
                <x-icon name="book"/>
                <h3>{{ __('No issues yet') }}</h3>
                <p>{{ __('The first issue is on its way') }}</p>
            </div>
        @else
            <div class="grid-4">
                @foreach ($issues as $issue)
                    <a href="{{ lroute('maqolavolume', $issue->id) }}" class="issue-card">
                        @include('site.partials.cover', ['issue' => $issue])
                        <div>
                            <h3>{{ $issue->title }}</h3>
                            <span>@if ($issue->articles_count){{ trans_choice(':count paper|:count papers', $issue->articles_count) }} · @endif{{ optional($issue->created_at)->format('d.m.Y') }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
            {{ $issues->links() }}
        @endif
    </div>
</section>
@endsection
