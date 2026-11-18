@php
    use App\Models\Paper;
    use App\Support\Locale;
    $pending = Paper::whereIn('status', [Paper::SUBMITTED, Paper::UNDER_REVIEW])->count();
    $nav = [
        ['admin.dashboard', 'admin.dashboard', 'home', 'Dashboard'],
        ['admin.visitors', 'admin.visitors', 'eye', 'Visitors'],
        ['admin.papers.index', 'admin.papers.*', 'inbox', 'Submissions'],
        ['admin.issues.index', 'admin.issues.*', 'book', 'Issues'],
        ['admin.articles.index', 'admin.articles.*', 'file', 'Articles'],
        ['admin.authors.index', 'admin.authors.*', 'users', 'Authors'],
        ['admin.pages.index', 'admin.pages.*', 'edit', 'Pages'],
        ['admin.system', 'admin.system*', 'layers', 'System'],
        ['admin.account', 'admin.account*', 'lock', 'Account'],
    ];
    // Editors (journal managers) see everything except the System (settings) page.
    if (! auth()->user()?->isAdmin()) {
        $nav = array_values(array_filter($nav, fn ($item) => $item[0] !== 'admin.system'));
    }
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ __('Admin') }} · {{ config('journal.short_name') }}</title>
    <link rel="icon" href="{{ asset('admin/dist/img/journalicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ @filemtime(public_path('css/site.css')) ?: 4 }}">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v=2">
    @stack('head')
</head>
<body class="admin">
<div class="shell" id="shell">
    <aside class="sidebar" id="sidebar">
        <button class="side-close" type="button" data-nav-close aria-label="{{ __('Close') }}"><x-icon name="x"/></button>
        <a href="{{ route('admin.dashboard') }}" class="brand">
            <span class="brand-mark"><x-mark/></span>
            <span class="brand-name"><strong>{{ config('journal.short_name') }}</strong><span>{{ __('Admin panel') }}</span></span>
        </a>
        @foreach ($nav as [$route, $pattern, $icon, $label])
            <a href="{{ route($route) }}" @class(['side-link', 'active' => request()->routeIs($pattern)])>
                <x-icon :name="$icon"/> {{ __($label) }}
                @if ($route === 'admin.papers.index' && $pending)
                    <span class="count">{{ $pending }}</span>
                @endif
            </a>
        @endforeach
        <div class="side-label">{{ __('Website') }}</div>
        <a href="{{ lroute('maqolabosh') }}" class="side-link" target="_blank" rel="noopener"><x-icon name="external"/> {{ __('Open website') }}</a>
        <div class="bottom">
            <div class="side-label" style="padding-top:0">{{ __('Language') }}</div>
            <div style="display:flex;gap:6px;padding:0 6px 12px">
                @foreach (Locale::LABELS as $code => $label)
                    <a href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}" class="side-link" style="flex:1;justify-content:center;padding:6px;{{ app()->getLocale() === $code ? 'background:rgba(123,147,255,.16);color:#fff' : '' }}">{{ strtoupper($code) }}</a>
                @endforeach
            </div>
            <a href="{{ route('adminlogout') }}" class="side-link"><x-icon name="logout"/> {{ __('Log out') }}</a>
        </div>
    </aside>
    <div class="nav-backdrop" data-nav-close></div>

    <div>
        <header class="topbar">
            <button class="icon-btn admin-toggle" type="button" data-nav-open aria-controls="sidebar" aria-expanded="false" aria-label="{{ __('Menu') }}"><x-icon name="menu"/></button>
            <h1>@yield('title')</h1>
            <div class="right">
                @yield('actions')
                <span class="who">{{ auth()->user()->name }}</span>
            </div>
        </header>
        <main class="admin-main">
            @if (\App\Support\SchemaState::pendingMigrations() && ! request()->routeIs('admin.system'))
                <div class="alert alert-bad"><x-icon name="alert"/><span>{{ __('A database update is needed after the last upload.') }} <a href="{{ route('admin.system') }}"><strong>{{ __('Open System → Run database update') }}</strong></a></span></div>
            @endif
            @if (session('success'))
                <div class="alert alert-ok"><x-icon name="check"/><span>{{ session('success') }}</span></div>
            @endif
            @if (session('error'))
                <div class="alert alert-bad"><x-icon name="alert"/><span>{{ session('error') }}</span></div>
            @endif
            @if ($errors->any())
                <div class="alert alert-bad"><x-icon name="alert"/><span>{{ $errors->first() }}</span></div>
            @endif
            @if (session('output'))
                <pre class="card card-pad" style="white-space:pre-wrap;font-size:13px;margin:0 0 20px;overflow-x:auto">{{ session('output') }}</pre>
            @endif
            @yield('content')
        </main>
    </div>
</div>
<script>
    // Mobile sidebar: opens from the menu button; closes with ×, a tap outside, Escape or a menu link.
    (function () {
        var shell = document.getElementById('shell');
        var opener = document.querySelector('[data-nav-open]');
        function setOpen(open) {
            shell.classList.toggle('nav-open', open);
            opener && opener.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        opener && opener.addEventListener('click', function () { setOpen(!shell.classList.contains('nav-open')); });
        document.querySelectorAll('[data-nav-close], #sidebar a').forEach(function (el) {
            el.addEventListener('click', function () { setOpen(false); });
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
        // Restored from the back/forward cache with the menu still open.
        window.addEventListener('pageshow', function () { setOpen(false); });
    })();

    // Long-running buttons show a waiting text and cannot be pressed twice.
    document.querySelectorAll('button[data-busy]').forEach(function (btn) {
        btn.form && btn.form.addEventListener('submit', function () {
            setTimeout(function () { btn.disabled = true; btn.textContent = btn.dataset.busy; }, 0);
        });
    });
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm(form.dataset.confirm)) e.preventDefault();
        });
    });
</script>
@stack('scripts')
</body>
</html>
