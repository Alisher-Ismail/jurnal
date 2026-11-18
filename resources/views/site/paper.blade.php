@extends('layouts.site')

@section('title', __('Paper').' '.$paper->code)

@php
    $status = $paper->status;
    $steps = [
        'submitted' => __('Submitted'),
        'under_review' => __('Under review'),
        'final' => $status === 'rejected' ? __('Not accepted') : __('Published'),
    ];
    $reached = ['submitted' => 1, 'under_review' => 2, 'accepted' => 3, 'rejected' => 3][$status] ?? 1;
    // With "certificate after payment" there is one certificate: the publication one, once paid.
    $payMode = \App\Models\Paper::certificateNeedsPayment();
    $certificate = $payMode ? null : $paper->submissionCertificate;
    $tgLink = $paper->telegramStartLink();
@endphp

@push('meta')
    <meta name="robots" content="noindex">
@endpush

@section('content')
<header class="page-head">
    <div class="wrap">
        <nav class="crumbs"><a href="{{ lroute('maqolabosh') }}">{{ __('Home') }}</a><a href="{{ lroute('submit') }}">{{ __('Submit a paper') }}</a><span>{{ $paper->code }}</span></nav>
        <span class="badge badge-{{ $status }}">{{ __('status.'.$status) }}</span>
        <h1 style="margin-top:14px;max-width:none;font-size:clamp(26px,3.4vw,38px)">{{ $paper->title }}</h1>
        <p>{{ $paper->authors }}</p>
    </div>
</header>

<section class="section-tight" style="padding-top:40px">
    <div class="wrap submit-layout">
        <div>
            @if (session('submitted'))
                <div class="alert alert-ok">
                    <x-icon name="check"/>
                    <span><strong>{{ __('Your paper has been received') }}</strong><br>
                        @if ($payMode && ! $paper->paymentRequired())
                            {{ __('The editors will review it.') }} 🎁 {{ __('Publication in this issue is free.') }} {{ __('The publication certificate is sent when the paper is published.') }}
                        @elseif ($payMode)
                            {{ __('The editors will review it. The payment details are on the right; the publication certificate is sent after the paper is published and the payment is confirmed.') }}
                        @else
                            {{ $paper->email ? __('The submission certificate was sent to :email.', ['email' => $paper->email]) : __('Download your submission certificate below.') }}
                        @endif
                    </span>
                </div>
            @endif

            <div class="card card-pad">
                <div style="display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;align-items:center;margin-bottom:26px">
                    <div>
                        <div class="muted small" style="margin-bottom:8px">{{ __('Tracking code') }}</div>
                        <span class="code-box">{{ $paper->code }}</span>
                    </div>
                    <div class="muted small" style="text-align:right">
                        {{ __('Submitted') }}: <strong style="color:var(--ink)">{{ $paper->created_at->format('d.m.Y H:i') }}</strong><br>
                        {{ __('Keep this code to check the status later.') }}
                    </div>
                </div>

                <ul class="timeline">
                    @foreach ($steps as $key => $label)
                        @php($i = $loop->iteration)
                        <li @class([
                            'done' => $i < $reached || ($i === 3 && $status === 'accepted'),
                            'now' => $i === $reached && ! in_array($status, ['accepted', 'rejected'], true),
                            'fail' => $i === 3 && $status === 'rejected',
                        ])>
                            <span class="dot">
                                @if ($i === 3 && $status === 'rejected')<x-icon name="x"/>@elseif ($i < $reached || ($i === 3 && $status === 'accepted'))<x-icon name="check"/>@endif
                            </span>
                            {{ $label }}
                            @if ($i === 1)
                                <small>{{ $paper->created_at->format('d.m.Y') }}</small>
                            @elseif ($i === 2 && $paper->reviewed_at)
                                <small>{{ $paper->reviewed_at->format('d.m.Y') }}</small>
                            @elseif ($i === 3 && $paper->published_at)
                                <small>{{ $paper->published_at->format('d.m.Y') }}</small>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if ($status === 'accepted' && $paper->article)
                    <div class="alert alert-ok" style="margin:6px 0 0">
                        <x-icon name="award"/>
                        <span>{{ __('Your paper is published. Permanent link:') }}<br>
                            <a href="{{ lroute('maqola', $paper->article->id) }}"><strong>{{ lroute('maqola', $paper->article->id) }}</strong></a>
                        </span>
                    </div>
                @elseif ($status === 'rejected' && $paper->admin_note)
                    <div class="alert alert-bad" style="margin:6px 0 0"><x-icon name="info"/><span><strong>{{ __('Editor comment') }}:</strong> {{ $paper->admin_note }}</span></div>
                @endif
            </div>
        </div>

        <aside>
            @if ($payMode && ! $paper->paymentRequired() && ! $paper->certificateAllowed() && $status !== 'rejected')
                <div class="card side-box">
                    <h4>🎁 {{ __('Free publication') }}</h4>
                    <p class="muted small" style="margin:0">{{ __('Publication in this issue is free.') }} {{ __('The publication certificate is sent when the paper is published.') }}</p>
                </div>
            @endif
            @if ($payMode && $paper->paymentRequired() && ! $paper->certificateAllowed() && $status !== 'rejected')
                @php($profile = \App\Support\JournalProfile::get())
                <div class="card side-box">
                    <h4>💳 {{ __('Publication fee') }}: {{ $profile['payment_fee'] }}</h4>
                    @if ($paper->isPaid())
                        <p class="small" style="margin:0"><strong>{{ __('Payment confirmed') }}</strong></p>
                    @else
                        <p class="small" style="margin:0 0 10px;white-space:pre-line">{{ $profile['payment_info'] }}</p>
                    @endif
                    <p class="muted small" style="margin:0">{{ $paper->article
                        ? __('Your publication certificate will be sent as soon as the payment is confirmed.')
                        : __('The publication certificate is sent after the paper is published and the payment is confirmed.') }}</p>
                </div>
            @endif
            @if (! $payMode && ! $certificate && $paper->isOpen())
                <div class="card side-box">
                    <h4>{{ __('Submission certificate') }}</h4>
                    <p class="muted small" style="margin:0">{{ optional($paper->check)->certificate_held
                        ? __('Your paper needs an additional check by the editors before the certificate is issued. We will notify you.')
                        : __('Your certificate with a QR code will follow after an automatic originality check (usually within a few minutes).') }}</p>
                </div>
            @endif
            @if ($certificate)
                <div class="card side-box">
                    <h4>{{ __('Submission certificate') }}</h4>
                    <p class="muted small">{{ __('Certificate number') }}: <strong style="color:var(--ink)">{{ $certificate->code }}</strong></p>
                    <a class="btn btn-primary" href="{{ route('certificate.download', $certificate) }}" target="_blank" rel="noopener"><x-icon name="download"/> {{ __('Download certificate') }}</a>
                </div>
            @endif
            @foreach ((! $payMode || $paper->certificateAllowed()) ? $paper->certificates()->where('type', 'publication')->get() : [] as $publication)
                <div class="card side-box">
                    <h4>{{ __('Publication certificate') }}</h4>
                    <a class="btn btn-dark" href="{{ route('certificate.download', $publication) }}" target="_blank" rel="noopener"><x-icon name="award"/> {{ __('Download certificate') }}</a>
                </div>
            @endforeach
            @if ($tgLink && ! $paper->telegram_chat_id)
                <div class="card side-box">
                    <h4>{{ __('Updates in Telegram') }}</h4>
                    <p class="muted small">{{ __('Open the bot to receive the certificate and every status change in Telegram.') }}</p>
                    <a class="btn btn-tg" href="{{ $tgLink }}" target="_blank" rel="noopener"><x-icon name="telegram"/> {{ __('Connect Telegram') }}</a>
                </div>
            @endif

            @include('site.partials.channel-box')
        </aside>
    </div>
</section>
@endsection
