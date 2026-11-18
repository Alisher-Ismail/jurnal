@extends('layouts.site')

@section('title', __('Certificate').' '.$certificate->code)

@php
    $article = $certificate->article;
    $paper = $certificate->paper;
    $isPublication = $certificate->type === 'publication';
@endphp

@push('meta')
    <meta name="robots" content="noindex">
@endpush

@section('content')
<section class="section">
    <div class="wrap" style="max-width:760px">
        <div class="card card-pad verify">
            <div class="seal"><x-icon name="shield"/></div>
            <span class="eyebrow" style="color:var(--ok)">{{ __('Certificate is valid') }}</span>
            <h1 style="font-size:clamp(24px,3vw,32px)">{{ $isPublication ? __('Publication certificate') : __('Submission certificate') }}</h1>
            <p class="muted">{{ __('This certificate was issued by :journal and is registered in our database.', ['journal' => config('journal.name')]) }}</p>

            <dl>
                <dt>{{ __('Certificate number') }}</dt><dd><code>{{ $certificate->code }}</code></dd>
                <dt>{{ __('Paper') }}</dt><dd>{{ $certificate->title }}</dd>
                <dt>{{ __('Authors') }}</dt><dd>{{ $certificate->recipient }}</dd>
                <dt>{{ __('Issued') }}</dt><dd>{{ optional($certificate->issued_at)->format('d.m.Y') }}</dd>
                @if ($article && $article->issue)
                    <dt>{{ __('Issue') }}</dt><dd>{{ $article->issue->title }}</dd>
                @endif
                @if ($paper)
                    <dt>{{ __('Tracking code') }}</dt><dd>{{ $paper->code }} · <span class="badge badge-{{ $paper->status }}">{{ __('status.'.$paper->status) }}</span></dd>
                @endif
            </dl>

            <div class="btn-row" style="justify-content:center">
                <a class="btn btn-primary" href="{{ route('certificate.download', $certificate) }}" target="_blank" rel="noopener"><x-icon name="download"/> {{ __('Download certificate') }}</a>
                @if ($article)
                    <a class="btn btn-ghost" href="{{ lroute('maqola', $article->id) }}">{{ __('Open the paper') }} <x-icon name="arrow-right"/></a>
                @endif
            </div>
        </div>
    </div>
</section>
@endsection
