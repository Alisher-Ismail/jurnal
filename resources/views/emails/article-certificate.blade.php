@extends('emails.layout')

@section('body')
    <h1 style="font-size:21px;margin:0 0 14px;color:#0d1424">{{ __('Publication certificate') }}</h1>
    <p style="margin:0 0 18px;padding:12px 14px;background:#f4f2ed;border-radius:10px"><em>«{{ $article->title }}»</em><br>
        <span style="font-size:13px;color:#5d6779">{{ $article->author }}</span></p>
    <p>{{ __('Your publication certificate is attached.') }}</p>
    <p>{{ __('Permanent link') }}:<br><a href="{{ lroute('maqola', $article->id, $certificate->locale) }}">{{ lroute('maqola', $article->id, $certificate->locale) }}</a></p>
    <p style="font-size:13px;color:#5d6779;margin-top:22px">{{ __('Anyone can verify the certificate by scanning its QR code or at') }} <a href="{{ $certificate->verifyUrl() }}">{{ $certificate->verifyUrl() }}</a></p>
@endsection
