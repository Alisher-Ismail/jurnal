@extends('layouts.site')

@section('title', __('Topics'))
@section('document_title', __('Scientific articles by topic — :journal', ['journal' => config('journal.name')]))
@section('description', __('Browse :count topics of open-access scientific articles: economics, education, digital technologies, medicine, philology and more. Full text PDF with DOI.', ['count' => $topics->count()]))
@section('canonical', \App\Support\Locale::route('mavzular', [], 'uz'))

@section('content')
<header class="page-head">
    <div class="wrap">
        <nav class="crumbs"><a href="{{ lroute('maqolabosh') }}">{{ __('Home') }}</a><span>{{ __('Topics') }}</span></nav>
        <span class="eyebrow">{{ __('Topics') }}</span>
        <h1>{{ __('Scientific articles by topic') }}</h1>
        <p>{{ __(':count topics, each with several articles. Choose a topic to see all its papers.', ['count' => $topics->count()]) }}</p>
    </div>
</header>

<section class="section-tight" style="padding-top:36px">
    <div class="wrap">
        <div class="card card-pad">
            <div class="tags" style="gap:8px">
                @foreach ($topics as $t)
                    <a class="tag" href="{{ lroute('mavzu', $t->slug) }}" style="{{ $t->articles_count >= 10 ? 'font-weight:600;color:var(--ink)' : '' }}">{{ $t->name }} <span style="opacity:.6;margin-left:5px">{{ $t->articles_count }}</span></a>
                @endforeach
            </div>
        </div>
    </div>
</section>
@endsection
