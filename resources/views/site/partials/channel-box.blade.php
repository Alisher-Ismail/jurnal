{{-- Telegram channel invitation: a side box, or a wide band with $wide = true. --}}
@php
    $channelUrl = config('journal.telegram_channel');
    $channelHandle = $channelUrl ? '@'.basename((string) parse_url($channelUrl, PHP_URL_PATH)) : null;
@endphp
@if ($channelUrl)
    @if (! empty($wide))
        <div class="card card-pad channel-band">
            <span class="channel-icon"><x-icon name="telegram"/></span>
            <div>
                <span class="eyebrow">{{ __('Our Telegram channel') }} · {{ $channelHandle }}</span>
                <p>{{ __('New issues, submission deadlines and announcements — first in our channel.') }}</p>
            </div>
            <a class="btn btn-tg btn-lg" href="{{ $channelUrl }}" target="_blank" rel="noopener"><x-icon name="telegram"/> {{ __('Subscribe') }}</a>
        </div>
    @else
        <div class="card side-box channel-side">
            <h4>{{ __('Our Telegram channel') }}</h4>
            <p class="muted small" style="margin:0 0 12px">{{ __('New issues, submission deadlines and announcements — first in our channel.') }}</p>
            <a class="btn btn-tg" href="{{ $channelUrl }}" target="_blank" rel="noopener"><x-icon name="telegram"/> {{ __('Subscribe') }} · {{ $channelHandle }}</a>
        </div>
    @endif
@endif
