@extends('layouts.site')

@section('title', __('Contact'))

@php($bot = config('services.telegram.username'))

@section('content')
<header class="page-head">
    <div class="wrap">
        <nav class="crumbs"><a href="{{ lroute('maqolabosh') }}">{{ __('Home') }}</a><span>{{ __('Contact') }}</span></nav>
        <h1>{{ __('Contact') }}</h1>
        <p>{{ __('Questions about a submission or the journal? We usually reply within one working day.') }}</p>
    </div>
</header>

<section class="section-tight" style="padding-top:44px">
    <div class="wrap grid-3">
        <div class="card card-pad">
            <div class="step" style="padding:0">
                <div class="num"><x-icon name="pin"/></div>
                <h3>{{ __('Address') }}</h3>
                <p class="muted">{{ config('journal.address') }}</p>
            </div>
        </div>
        <div class="card card-pad">
            <div class="step" style="padding:0">
                <div class="num"><x-icon name="users"/></div>
                <h3>{{ __('Editorial office') }}</h3>
                <p class="muted">{{ config('journal.editor_name') }}</p>
                <a href="{{ config('journal.telegram_admin') }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="margin-top:12px"><x-icon name="telegram"/> {{ __('Write on Telegram') }}</a>
                @if (config('journal.email'))
                    <a href="mailto:{{ config('journal.email') }}" class="btn btn-ghost btn-sm" style="margin-top:12px"><x-icon name="mail"/> {{ config('journal.email') }}</a>
                @endif
            </div>
        </div>
        <div class="card card-pad">
            <div class="step" style="padding:0">
                <div class="num"><x-icon name="send"/></div>
                <h3>{{ __('Telegram channel') }}</h3>
                <p class="muted">{{ __('News, calls for papers and new issues.') }}</p>
                <a href="{{ config('journal.telegram_channel') }}" target="_blank" rel="noopener" class="btn btn-tg btn-sm" style="margin-top:12px"><x-icon name="telegram"/> {{ __('Subscribe') }}</a>
                @if ($bot)
                    <a href="https://t.me/{{ ltrim($bot, '@') }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="margin-top:12px">{{ __('Telegram bot') }}</a>
                @endif
            </div>
        </div>
    </div>
</section>
@endsection
