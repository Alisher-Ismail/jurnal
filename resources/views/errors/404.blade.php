@extends('layouts.site')

@section('title', __('Page not found'))

@section('content')
<section class="section">
    <div class="wrap" style="max-width:640px;text-align:center">
        <div class="eyebrow" style="justify-content:center">404</div>
        <h1 style="font-size:clamp(30px,4vw,44px)">{{ __('Page not found') }}</h1>
        <p class="muted">{{ __('The page may have been moved. Try the archive or search.') }}</p>
        <div class="btn-row" style="justify-content:center;margin-top:24px">
            <a href="{{ lroute('maqolabosh') }}" class="btn btn-primary">{{ __('Home') }}</a>
            <a href="{{ lroute('mvolume') }}" class="btn btn-ghost">{{ __('Archive') }}</a>
        </div>
    </div>
</section>
@endsection
