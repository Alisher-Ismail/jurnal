<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Log in') }} · {{ config('journal.short_name') }}</title>
    <link rel="icon" href="{{ asset('admin/dist/img/journalicon.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ @filemtime(public_path('css/site.css')) ?: 4 }}">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v=2">
</head>
<body>
<div class="login-wrap">
    <form class="card login-card" method="post" action="{{ route('adminlogin.post') }}">
        @csrf
        <a href="{{ lroute('maqolabosh') }}" class="brand" style="margin-bottom:26px">
            <span class="brand-mark"><x-mark/></span>
            <span class="brand-name"><strong>{{ config('journal.short_name') }}</strong><span>{{ __('Admin panel') }}</span></span>
        </a>
        <h1 style="font-size:24px;margin-bottom:6px">{{ __('Log in') }}</h1>
        <p class="muted small" style="margin-bottom:22px">{{ __('Editors and administrators only.') }}</p>

        @if (session('error'))
            <div class="alert alert-bad"><x-icon name="alert"/><span>{{ session('error') }}</span></div>
        @endif
        @if ($errors->any())
            <div class="alert alert-bad"><x-icon name="alert"/><span>{{ $errors->first() }}</span></div>
        @endif

        <div class="field" style="margin-bottom:14px">
            <label for="email">{{ __('E-mail') }}</label>
            <input class="input" id="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        </div>
        <div class="field" style="margin-bottom:14px">
            <label for="password">{{ __('Password') }}</label>
            <input class="input" type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <label class="check" style="margin-bottom:22px"><input type="checkbox" name="remember" value="1"> <span>{{ __('Remember me') }}</span></label>
        <button class="btn btn-primary" style="width:100%" type="submit"><x-icon name="lock"/> {{ __('Log in') }}</button>
    </form>
</div>
</body>
</html>
