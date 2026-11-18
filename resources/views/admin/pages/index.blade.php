@extends('layouts.admin')

@section('title', __('Pages'))

@section('content')
<p class="muted" style="margin-top:0">{{ __('Each page has its own text in Uzbek, English and Russian. Visitors see the Uzbek text when a translation is missing.') }}</p>
<div class="grid-2">
    @foreach (\App\Support\PageContent::PAGES as $key => $page)
        <div class="card card-pad">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
                <h2 style="font:600 17px var(--sans);margin:0">{{ __($page['title']) }}</h2>
                <a href="{{ lroute($page['route']) }}" target="_blank" rel="noopener" class="icon-btn" title="{{ __('Public page') }}"><x-icon name="external"/></a>
            </div>
            <div class="lang-grid">
                @foreach (\App\Support\Locale::LABELS as $code => $label)
                    <a href="{{ route('admin.pages.edit', [$key, $code]) }}">
                        {{ $label }}
                        <span class="{{ $status[$key][$code] ? 'ok' : 'missing' }}">{{ $status[$key][$code] ? '●' : __('empty') }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
@endsection
