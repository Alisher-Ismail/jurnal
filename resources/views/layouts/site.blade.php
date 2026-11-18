@php
    use App\Support\Locale;
    $locale = app()->getLocale();
    $journal = config('journal.name');
    $pageTitle = trim($__env->yieldContent('title'));
    $description = trim($__env->yieldContent('description')) ?: __('Emro Science and Education is an open-access scientific journal: publish your paper online or via Telegram, with a DOI from Zenodo, indexed in Google Scholar, OpenAIRE and DataCite.');
    $bot = config('services.telegram.username');
    $issnLabel = \App\Support\JournalProfile::issnLabel();
    $journalLinks = [
        'haqimiz' => 'About the journal',
        'maqsadimiz' => 'Aims and scope',
        'jamoa' => 'Editorial board',
        'yoriqnoma' => 'Author guidelines',
        'kochirma' => 'Plagiarism policy',
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="google-site-verification" content="IkjKnq0NFaM2WHmLho95Ds1EqsvN2UKPz40ytVZGaWU">
    <title>{{ trim($__env->yieldContent('document_title')) ?: ($pageTitle ? $pageTitle.' · '.$journal : $journal) }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit($description, 300) }}">
    <link rel="canonical" href="{{ trim($__env->yieldContent('canonical')) ?: url()->current() }}">
    @foreach (Locale::all() as $code)
        <link rel="alternate" hreflang="{{ $code }}" href="{{ Locale::switchUrl($code) }}">
    @endforeach
    <meta property="og:site_name" content="{{ $journal }}">
    <meta property="og:title" content="{{ $pageTitle ?: $journal }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit($description, 300) }}">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ asset('admin/dist/img/journal.png') }}">
    @stack('meta')
    <link rel="icon" href="{{ asset('admin/dist/img/journalicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;1,8..60,500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ @filemtime(public_path('css/site.css')) ?: 4 }}">
</head>
<body>
<a href="#main" class="sr-only">{{ __('Skip to content') }}</a>

<header class="site-header">
    <div class="wrap">
        <nav class="nav" id="nav" aria-label="{{ __('Main navigation') }}">
            <a href="{{ lroute('maqolabosh') }}" class="brand" aria-label="{{ $journal }}">
                <span class="brand-mark"><x-mark/></span>
                <span class="brand-name"><strong>{{ $journal }}</strong><span>{{ $issnLabel ?: __('Scientific journal') }}</span></span>
            </a>

            <div class="nav-links">
                <a href="{{ lroute('mvolume') }}" @class(['active' => request()->routeIs('mvolume*', 'maqolavolume*')])>{{ __('Archive') }}</a>
                <a href="{{ lroute('mavzular') }}" @class(['active' => request()->routeIs('mavzu*')])>{{ __('Topics') }}</a>
                <details class="nav-drop">
                    <summary>{{ __('Journal') }} <x-icon name="chevron-down"/></summary>
                    <div class="drop-menu">
                        @foreach ($journalLinks as $route => $label)
                            <a href="{{ lroute($route) }}">{{ __($label) }}</a>
                        @endforeach
                    </div>
                </details>
                <a href="{{ lroute('submit') }}" @class(['active' => request()->routeIs('submit*')])>{{ __('Submit a paper') }}</a>
                <a href="{{ lroute('contact') }}" @class(['active' => request()->routeIs('contact*')])>{{ __('Contact') }}</a>
            </div>

            <div class="nav-right">
                <form class="nav-search" action="{{ lroute('maqolaqidiruv') }}" method="get" role="search">
                    <x-icon name="search"/>
                    <input type="search" name="search" value="{{ request('search') }}" placeholder="{{ __('Search papers, authors…') }}" aria-label="{{ __('Search') }}">
                </form>
                <details class="nav-drop lang-drop">
                    <summary aria-label="{{ __('Language') }}"><x-icon name="globe"/> {{ $locale === 'en' ? 'EN' : strtoupper($locale) }}</summary>
                    <div class="drop-menu">
                        @foreach (Locale::LABELS as $code => $label)
                            <a href="{{ Locale::switchUrl($code) }}" hreflang="{{ $code }}" @class(['current' => $code === $locale])>{{ $label }}</a>
                        @endforeach
                    </div>
                </details>
                <button class="menu-toggle" type="button" aria-label="{{ __('Menu') }}" aria-expanded="false" data-menu>
                    <x-icon name="menu"/>
                </button>
            </div>
        </nav>
    </div>
</header>

<main id="main">
    @yield('content')
</main>

<footer class="site-footer">
    <div class="wrap">
        <div class="foot-grid">
            <div>
                <a href="{{ lroute('maqolabosh') }}" class="brand" style="margin-bottom:18px">
                    <span class="brand-mark"><x-mark/></span>
                    <span class="brand-name"><strong>{{ $journal }}</strong><span>{{ __('Scientific journal') }}</span></span>
                </a>
                <p style="max-width:36ch">{{ __('An open-access peer-reviewed journal publishing research in science and education.') }}</p>
                @if ($issnLabel)
                    <p>{{ $issnLabel }}</p>
                @endif
            </div>
            <div>
                <h4>{{ __('Journal') }}</h4>
                <ul>
                    @foreach ($journalLinks as $route => $label)
                        <li><a href="{{ lroute($route) }}">{{ __($label) }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div>
                <h4>{{ __('For authors') }}</h4>
                <ul>
                    <li><a href="{{ lroute('submit') }}">{{ __('Submit a paper') }}</a></li>
                    <li><a href="{{ lroute('guide') }}">{{ __('How to publish a paper') }}</a></li>
                    @if ($bot)
                        <li><a href="https://t.me/{{ ltrim($bot, '@') }}" target="_blank" rel="noopener">{{ __('Telegram bot') }}</a></li>
                    @endif
                    <li><a href="{{ lroute('mvolume') }}">{{ __('Archive') }}</a></li>
                    <li><a href="{{ lroute('mavzular') }}">{{ __('Topics') }}</a></li>
                    <li><a href="{{ lroute('submit') }}#track">{{ __('Verify a certificate') }}</a></li>
                </ul>
            </div>
            <div>
                <h4>{{ __('Contact') }}</h4>
                <ul>
                    <li>{{ config('journal.address') }}</li>
                    @if (config('journal.email'))
                        <li><a href="mailto:{{ config('journal.email') }}">{{ config('journal.email') }}</a></li>
                    @endif
                    <li><a href="{{ config('journal.telegram_channel') }}" target="_blank" rel="noopener">{{ __('Telegram channel') }}</a></li>
                    <li><a href="{{ config('journal.telegram_admin') }}" target="_blank" rel="noopener">{{ __('Editorial office') }}</a></li>
                </ul>
            </div>
        </div>
        <div class="foot-bottom">
            <span>© {{ date('Y') }} {{ $journal }} · {{ __('License') }}: <a href="{{ config('journal.license_url') }}" rel="license noopener" target="_blank">{{ config('journal.license') }}</a></span>
            <span>{{ __('Publisher') }}: <a href="{{ config('journal.publisher_url') }}" target="_blank" rel="noopener">{{ config('journal.publisher') }}</a></span>
        </div>
    </div>
</footer>

<script>
    // Mobile menu toggle and closing open dropdowns on outside click.
    document.querySelector('[data-menu]')?.addEventListener('click', function () {
        const nav = document.getElementById('nav');
        this.setAttribute('aria-expanded', nav.classList.toggle('open'));
    });
    document.addEventListener('click', function (e) {
        document.querySelectorAll('details.nav-drop[open]').forEach(function (d) {
            if (!d.contains(e.target)) d.removeAttribute('open');
        });
    });
</script>
@include('site.partials.nudge')
@stack('scripts')
</body>
</html>
