<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:#f4f2ed;font-family:Arial,Helvetica,sans-serif;color:#1f2737">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f2ed;padding:28px 12px">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e4e2dc">
            <tr><td style="background:#0d1424;padding:22px 28px;color:#ffffff;font-size:17px;font-weight:bold;letter-spacing:.3px">
                {{ config('journal.name') }}
            </td></tr>
            <tr><td style="padding:28px;font-size:15px;line-height:1.6">
                @yield('body')
            </td></tr>
            <tr><td style="padding:18px 28px;background:#fbfaf7;border-top:1px solid #efede8;font-size:12px;color:#8a93a3">
                {{ config('journal.name') }} · <a href="{{ url('/') }}" style="color:#5d6779">{{ preg_replace('#^https?://#', '', url('/')) }}</a>
                @if (config('journal.telegram_channel'))
                    · Telegram: <a href="{{ config('journal.telegram_channel') }}" style="color:#5d6779">{{ '@'.basename((string) parse_url(config('journal.telegram_channel'), PHP_URL_PATH)) }}</a>
                @endif
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
