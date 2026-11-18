@extends('emails.layout')

@php
    $channelUrl = config('journal.telegram_channel');
    $handle = '@'.basename((string) parse_url($channelUrl, PHP_URL_PATH));
@endphp

@section('body')
    <h1 style="font-size:21px;margin:0 0 14px;color:#0d1424">{{ __('Join our Telegram channel') }}</h1>
    <p style="margin:0 0 12px">{{ __('Dear :name, thank you for publishing your paper in our journal!', ['name' => $paper->authors]) }}</p>
    <p style="margin:0 0 18px">{{ __('Join our Telegram channel to be the first to know about new issues, submission deadlines and announcements.') }}</p>
    <p style="margin:22px 0"><a href="{{ $channelUrl }}" style="display:inline-block;background:#229ed9;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold">📢 {{ __('Subscribe') }} · {{ $handle }}</a></p>
@endsection
