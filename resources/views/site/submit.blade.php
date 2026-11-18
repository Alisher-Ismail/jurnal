@extends('layouts.site')

@section('title', __('Submit a paper'))
@section('document_title', __('Online article submission — publish your scientific article').' | '.config('journal.name'))
@section('description', __('Submit your scientific article online or via Telegram: publication within 1 day after acceptance, a DOI from Zenodo, Google Scholar indexing and a certificate with a QR code. Articles in Uzbek, Russian and English.'))

@php
    $bot = config('services.telegram.username');
    $botUrl = $bot ? 'https://t.me/'.ltrim($bot, '@') : null;
    $maxMb = (int) floor(config('journal.max_upload_kb') / 1024);
@endphp


@php
    $fee = \App\Support\JournalProfile::get()['payment_fee'] ?? '';
    $faq = [
        [__('How do I submit an article online?'), __('Fill in the form on this page and attach your manuscript (Word or PDF), or send it to our Telegram bot. You receive a tracking code immediately and can follow every step online.')],
        [__('How long does publication take?'), __('Papers are reviewed and checked for plagiarism; accepted papers are published within 1 day.')],
        [__('How much does publication cost?'), $fee ? __('The publication fee is :fee and is paid only after the paper is accepted.', ['fee' => $fee]) : __('The publication fee is paid only after the paper is accepted.')],
        [__('Will my article get a DOI?'), __('Yes. Every published article receives a permanent DOI via Zenodo (CERN) and is indexed in Google Scholar, OpenAIRE and DataCite.')],
        [__('Do I receive a certificate?'), __('Yes. A publication certificate with a QR code that anyone can verify is sent after publication and payment confirmation.')],
        [__('Which languages and subjects are accepted?'), __('Articles in Uzbek, Russian and English in physics and mathematics, chemistry, biology, engineering, economics, philology, geography, law and pedagogy.')],
    ];
@endphp

@push('meta')
    <script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
<header class="page-head">
    <div class="wrap">
        <nav class="crumbs"><a href="{{ lroute('maqolabosh') }}">{{ __('Home') }}</a><span>{{ __('Submit a paper') }}</span></nav>
        <span class="eyebrow">{{ __('For authors') }}</span>
        <h1>{{ __('Submit a paper') }}</h1>
        <p>{{ __('Fill in the form below. Right after submitting you receive a certificate with a QR code and a tracking code.') }}</p>
    </div>
</header>

<section class="section-tight" style="padding-top:40px">
    <div class="wrap submit-layout">
        <div>
            <div class="channel-tabs">
                <a href="#form" class="channel on">
                    <span class="ic"><x-icon name="globe"/></span>
                    <span><b>{{ __('On the website') }}</b><span>{{ __('Certificate by e-mail') }}</span></span>
                </a>
                @if ($botUrl)
                    <a href="{{ $botUrl }}" class="channel" target="_blank" rel="noopener">
                        <span class="ic tg"><x-icon name="telegram"/></span>
                        <span><b>{{ __('Via Telegram bot') }}</b><span>{{ '@'.ltrim($bot, '@') }}</span></span>
                    </a>
                @endif
            </div>

            <form id="form" class="card card-pad" method="post" action="{{ route('papers.store') }}" enctype="multipart/form-data">
                @csrf
                @if ($errors->any())
                    <div class="alert alert-bad"><x-icon name="alert"/><span>{{ __('Please correct the highlighted fields.') }}</span></div>
                @endif
                <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">

                <div class="form-grid">
                    <div @class(['field full', 'has-error' => $errors->has('title')])>
                        <label for="title">{{ __('Paper title') }}</label>
                        <input class="input" id="title" name="title" value="{{ old('title') }}" maxlength="255" required>
                        @error('title')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <div @class(['field full', 'has-error' => $errors->has('authors')])>
                        <label for="authors">{{ __('Authors') }}</label>
                        <input class="input" id="authors" name="authors" value="{{ old('authors') }}" maxlength="255" placeholder="{{ __('e.g. Aliyev Vali, Karimova Nodira') }}" required>
                        <span class="hint">{{ __('Full names, separated by commas, in the order they should appear.') }}</span>
                        @error('authors')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <div @class(['field', 'has-error' => $errors->has('email')])>
                        <label for="email">{{ __('E-mail') }}</label>
                        <input class="input" type="email" id="email" name="email" value="{{ old('email') }}" required>
                        <span class="hint">{{ __('The certificate will be sent here.') }}</span>
                        @error('email')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <div @class(['field', 'has-error' => $errors->has('phone')])>
                        <label for="phone">{{ __('Phone') }} <span class="opt">{{ __('optional') }}</span></label>
                        <input class="input" type="tel" id="phone" name="phone" value="{{ old('phone') }}" maxlength="40" placeholder="+998 __ ___ __ __">
                        @error('phone')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <div @class(['field full', 'has-error' => $errors->has('affiliation')])>
                        <label for="affiliation">{{ __('Organisation') }} <span class="opt">{{ __('optional') }}</span></label>
                        <input class="input" id="affiliation" name="affiliation" value="{{ old('affiliation') }}" maxlength="255">
                        @error('affiliation')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <div @class(['field full', 'has-error' => $errors->has('abstract')])>
                        <label for="abstract">{{ __('Abstract') }}</label>
                        <textarea class="textarea" id="abstract" name="abstract" maxlength="5000" required>{{ old('abstract') }}</textarea>
                        <span class="hint">{{ __('50–5000 characters.') }}</span>
                        @error('abstract')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <div @class(['field full', 'has-error' => $errors->has('keywords')])>
                        <label for="keywords">{{ __('Keywords') }} <span class="opt">{{ __('optional') }}</span></label>
                        <input class="input" id="keywords" name="keywords" value="{{ old('keywords') }}" maxlength="255" placeholder="{{ __('Separated by commas') }}">
                        @error('keywords')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <div @class(['field full', 'has-error' => $errors->has('file')])>
                        <label for="file">{{ __('Manuscript') }}</label>
                        <label class="drop" id="drop">
                            <input type="file" id="file" name="file" accept=".pdf,.doc,.docx" required>
                            <x-icon name="upload"/>
                            <strong data-file-label>{{ __('Choose a file or drag it here') }}</strong>
                            <span>PDF, DOC, DOCX · {{ __('up to :size MB', ['size' => $maxMb]) }}</span>
                        </label>
                        @error('file')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <div @class(['field full', 'has-error' => $errors->has('agree')])>
                        <label class="check">
                            <input type="checkbox" name="agree" value="1" @checked(old('agree')) required>
                            <span>{!! __('I confirm that I am an author of this paper, that it is original and has not been published elsewhere, and I agree with the <a href=":guidelines" target="_blank">author guidelines</a> and <a href=":plagiarism" target="_blank">plagiarism policy</a>. Every submission is automatically checked for authorship and plagiarism.', ['guidelines' => lroute('yoriqnoma'), 'plagiarism' => lroute('kochirma')]) !!}</span>
                        </label>
                        @error('agree')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <div @class(['field full', 'has-error' => $errors->has('license')])>
                        <label class="check">
                            <input type="checkbox" name="license" value="1" @checked(old('license')) required>
                            <span>{!! __('If the paper is accepted, I agree that it is published in open access under the <a href=":license" target="_blank" rel="noopener">CC BY 4.0</a> license and deposited in <a href="https://zenodo.org" target="_blank" rel="noopener">Zenodo</a>, which gives it a permanent DOI.', ['license' => config('journal.license_url')]) !!}</span>
                        </label>
                        @error('license')<span class="error">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="btn-row" style="margin-top:26px;align-items:center">
                    <button type="submit" class="btn btn-primary btn-lg" data-submit><x-icon name="send"/> <span>{{ __('Submit paper') }}</span></button>
                    <span class="muted small">{{ __('You will receive a certificate with a QR code right away.') }}</span>
                </div>
            </form>
        </div>

        <aside>
            <div class="card side-box" id="track">
                <h4>{{ __('Track a paper or verify a certificate') }}</h4>
                @if (session('track_error'))
                    <div class="alert alert-bad" style="margin-bottom:12px"><x-icon name="alert"/><span>{{ session('track_error') }}</span></div>
                @endif
                <form action="{{ route('papers.track') }}" method="get" style="display:grid;gap:10px">
                    <input class="input" name="code" placeholder="ES-{{ date('y') }}-XXXXXX" required aria-label="{{ __('Code') }}">
                    <button class="btn btn-dark" type="submit"><x-icon name="search"/> {{ __('Check') }}</button>
                </form>
            </div>

            @if ($guidelines && filled(strip_tags($guidelines)))
                <div class="card side-box">
                    <details class="guide" open>
                        <summary>{{ __('Submission rules') }} <x-icon name="chevron-down" style="width:18px;height:18px"/></summary>
                        <div class="prose" style="font-size:15px">{!! $guidelines !!}</div>
                    </details>
                </div>
            @endif

            <div class="card side-box">
                <h4>{{ __('What happens next') }}</h4>
                <ul class="timeline">
                    <li class="now"><span class="dot"></span>{{ __('Submitted') }}<small>{{ __('Certificate and tracking code') }}</small></li>
                    <li><span class="dot"></span>{{ __('Under review') }}<small>{{ __('Quality and plagiarism check') }}</small></li>
                    <li><span class="dot"></span>{{ __('Published') }}<small>{{ __('Permanent link and publication certificate') }}</small></li>
                </ul>
            </div>

            @include('site.partials.channel-box')
        </aside>
    </div>
</section>

<section class="section-tight" style="padding-top:0">
    <div class="wrap">
        <div class="card card-pad">
            <h2 style="font-size:22px;margin-bottom:14px">{{ __('Questions about submitting an article') }}</h2>
            @foreach ($faq as [$question, $answer])
                <details class="guide" @if ($loop->first) open @endif style="border-top:1px solid var(--line);padding:12px 0">
                    <summary style="font-weight:600;cursor:pointer">{{ $question }}</summary>
                    <p class="muted" style="margin:8px 0 0">{{ $answer }}</p>
                </details>
            @endforeach
            <p style="margin:16px 0 0"><a href="{{ lroute('guide') }}">{{ __('Requirements, steps and fee in detail') }} →</a></p>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script>
    (function () {
        const input = document.getElementById('file');
        const drop = document.getElementById('drop');
        const label = drop.querySelector('[data-file-label]');
        input.addEventListener('change', function () {
            if (input.files.length) {
                const f = input.files[0];
                label.textContent = f.name + ' · ' + (f.size / 1048576).toFixed(1) + ' MB';
            }
        });
        ['dragenter', 'dragover'].forEach(e => drop.addEventListener(e, () => drop.classList.add('drag')));
        ['dragleave', 'drop'].forEach(e => drop.addEventListener(e, () => drop.classList.remove('drag')));
        document.getElementById('form').addEventListener('submit', function () {
            const btn = this.querySelector('[data-submit]');
            btn.disabled = true;
            btn.querySelector('span').textContent = @json(__('Uploading…'));
        });
    })();
</script>
@endpush
