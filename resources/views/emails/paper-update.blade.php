@extends('emails.layout')

@php
    $button = 'display:inline-block;background:#2f4fd8;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold';
    $headings = [
        'submitted' => __('Your submission certificate'),
        'received' => __('Your paper has been received'),
        'held' => __('Your paper needs an additional check'),
        'under_review' => __('Your paper is now under review.'),
        'accepted' => __('Your paper has been accepted and published!'),
        'rejected' => __('Unfortunately, your paper was not accepted.'),
        'received_pay' => __('Your paper has been received'),
        'accepted_pay' => __('Your paper has been accepted and published!'),
        'paid' => __('Payment confirmed'),
    ];
    $profile = \App\Support\JournalProfile::get();
@endphp

@section('body')
    <h1 style="font-size:21px;margin:0 0 14px;color:#0d1424">{{ $headings[$event] ?? __('Paper update') }}</h1>
    <p style="margin:0 0 6px">{{ __('Dear :name,', ['name' => $paper->authors]) }}</p>
    <p style="margin:0 0 18px;padding:12px 14px;background:#f4f2ed;border-radius:10px"><em>«{{ $paper->title }}»</em><br>
        <span style="font-size:13px;color:#5d6779">{{ __('Tracking code') }}: <strong>{{ $paper->code }}</strong></span></p>

    @if ($event === 'submitted')
        <p>{{ __('Your submission certificate with a QR code is attached.') }} {{ __('The editors will review the paper and you will be notified about every change.') }}</p>
        <p style="margin:22px 0"><a href="{{ route('papers.show', $paper) }}" style="{{ $button }}">{{ __('Track your paper') }}</a></p>
    @elseif ($event === 'received')
        <p>{{ __('Your certificate with a QR code will follow after an automatic originality check (usually within a few minutes).') }}</p>
        <p style="margin:22px 0"><a href="{{ route('papers.show', $paper) }}" style="{{ $button }}">{{ __('Track your paper') }}</a></p>
    @elseif ($event === 'held')
        <p>{{ __('Your paper needs an additional check by the editors before the certificate is issued. We will notify you.') }}</p>
    @elseif ($event === 'under_review')
        <p>{{ __('The editors have started reviewing your paper.') }}</p>
        <p style="margin:22px 0"><a href="{{ route('papers.show', $paper) }}" style="{{ $button }}">{{ __('Track your paper') }}</a></p>
    @elseif ($event === 'accepted' && $paper->article)
        <p>{{ __('Your publication certificate is attached.') }}</p>
        <p>{{ __('Permanent link') }}:<br><a href="{{ lroute('maqola', $paper->article->id, $paper->locale) }}">{{ lroute('maqola', $paper->article->id, $paper->locale) }}</a></p>
        <p style="margin:22px 0"><a href="{{ lroute('maqola', $paper->article->id, $paper->locale) }}" style="{{ $button }}">{{ __('Open the paper') }}</a></p>
    @elseif ($event === 'received_pay')
        @if ($paper->paymentRequired())
            <p style="margin:18px 0;padding:12px 14px;background:#f4f2ed;border-radius:10px"><strong>{{ __('Publication fee') }}: {{ $profile['payment_fee'] }}</strong><br>{!! nl2br(e($profile['payment_info'])) !!}</p>
            <p>{{ __('The publication certificate is sent after the paper is published and the payment is confirmed.') }}</p>
        @else
            <p style="margin:18px 0;padding:12px 14px;background:#eaf6ee;border-radius:10px">🎁 <strong>{{ __('Publication in this issue is free.') }}</strong> {{ __('The publication certificate is sent when the paper is published.') }}</p>
        @endif
        <p style="margin:22px 0"><a href="{{ route('papers.show', $paper) }}" style="{{ $button }}">{{ __('Track your paper') }}</a></p>
    @elseif ($event === 'accepted_pay' && $paper->article)
        <p>{{ __('Permanent link') }}:<br><a href="{{ lroute('maqola', $paper->article->id, $paper->locale) }}">{{ lroute('maqola', $paper->article->id, $paper->locale) }}</a></p>
        <p style="margin:18px 0;padding:12px 14px;background:#f4f2ed;border-radius:10px"><strong>{{ __('Publication fee') }}: {{ $profile['payment_fee'] }}</strong><br>{!! nl2br(e($profile['payment_info'])) !!}</p>
        <p>{{ __('Your publication certificate will be sent as soon as the payment is confirmed.') }}</p>
    @elseif ($event === 'paid' && $paper->article)
        <p>{{ __('Your publication certificate is attached.') }}</p>
        <p style="margin:22px 0"><a href="{{ lroute('maqola', $paper->article->id, $paper->locale) }}" style="{{ $button }}">{{ __('Open the paper') }}</a></p>
    @elseif ($event === 'rejected')
        @if ($paper->admin_note)
            <p><strong>{{ __('Editor comment') }}:</strong> {{ $paper->admin_note }}</p>
        @endif
        <p>{{ __('You are welcome to revise the paper and submit it again.') }}</p>
    @endif

    @if (config('journal.telegram_channel') && $event !== 'rejected')
        <p style="margin:22px 0 0;padding:12px 14px;background:#e9f5fc;border-radius:10px;font-size:14px">📢 {{ __('New issues, submission deadlines and announcements — first in our channel.') }}<br>
            <a href="{{ config('journal.telegram_channel') }}" style="color:#1b8bc0;font-weight:bold">{{ __('Subscribe') }}: {{ '@'.basename((string) parse_url(config('journal.telegram_channel'), PHP_URL_PATH)) }}</a></p>
    @endif

    @if ($certificate)
        <p style="font-size:13px;color:#5d6779;margin-top:22px">{{ __('Anyone can verify the certificate by scanning its QR code or at') }} <a href="{{ $certificate->verifyUrl() }}">{{ $certificate->verifyUrl() }}</a></p>
    @endif
@endsection
