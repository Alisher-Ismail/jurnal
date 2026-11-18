@extends('layouts.admin')

@section('title', $article->exists ? __('Edit article') : __('Add article'))

@section('actions')
    @if ($article->exists)
        <a href="{{ lroute('maqola', $article->id) }}" class="btn btn-ghost btn-sm" target="_blank" rel="noopener"><x-icon name="external"/> {{ __('Public page') }}</a>
    @endif
@endsection

@section('content')
@if ($article->exists)
    <form id="convert-form" method="post" action="{{ route('admin.articles.convert', $article) }}">@csrf</form>
@endif
<div class="panel-grid">
    <form class="card card-pad" method="post" enctype="multipart/form-data"
          action="{{ $article->exists ? route('admin.articles.update', $article) : route('admin.articles.store') }}">
        @csrf
        @if ($article->exists) @method('PUT') @endif
        <div class="form-grid">
            <div class="field full">
                <label for="volume">{{ __('Issue') }}</label>
                <select class="select" id="volume" name="volume" required>
                    @foreach ($issues as $issue)
                        <option value="{{ $issue->id }}" @selected((string) old('volume', $article->volume) === (string) $issue->id)>{{ $issue->title }}</option>
                    @endforeach
                </select>
                @if ($issues->isEmpty())<span class="error">{{ __('Create an issue first in the admin panel.') }}</span>@endif
            </div>
            <div class="field full">
                <label for="title">{{ __('Title') }}</label>
                <input class="input" id="title" name="title" value="{{ old('title', $article->title) }}" required maxlength="255">
            </div>
            <div class="field full">
                <label for="author">{{ __('Authors') }}</label>
                <input class="input" id="author" name="author" value="{{ old('author', $article->author) }}" list="author-list" required maxlength="255" placeholder="{{ __('e.g. Aliyev Vali, Karimova Nodira') }}">
                <datalist id="author-list">@foreach ($authors as $name)<option value="{{ $name }}">@endforeach</datalist>
                <span class="hint">{{ __('Separate authors with commas. Each name gets its own author page.') }}</span>
            </div>
            <div class="field full">
                <label for="keywords">{{ __('Keywords') }} <span class="opt">{{ __('optional') }}</span></label>
                <input class="input" id="keywords" name="keywords" value="{{ old('keywords', $article->keywords) }}" maxlength="255">
            </div>
            <div class="field full">
                <label for="doi">DOI <span class="opt">{{ __('optional') }}</span></label>
                <input class="input" id="doi" name="doi" value="{{ old('doi', $article->doi) }}" maxlength="191" placeholder="10.5281/zenodo.1234567">
            </div>
            <div class="field full">
                <label for="abstract">{{ __('Abstract') }}</label>
                <textarea class="textarea" id="abstract" name="abstract" style="min-height:200px">{{ old('abstract', $article->abstract) }}</textarea>
            </div>
            <div class="field full">
                <label for="pdf">PDF @if ($article->exists)<span class="opt">{{ __('leave empty to keep the current file') }}</span>@endif</label>
                <input class="input" type="file" id="pdf" name="pdf" accept="application/pdf" @required(! $article->exists)>
                @if ($article->exists && $article->pdfProblem() === 'not_pdf')
                    <div class="alert alert-bad" style="margin:6px 0 0;font-size:13.5px"><x-icon name="alert"/>
                        <span>{{ __('The stored file is a Word document, not a PDF.') }}
                            <button type="submit" form="convert-form" class="btn btn-ghost btn-sm" style="margin-left:6px">{{ __('Convert to PDF') }}</button></span>
                    </div>
                @endif
                @if ($article->exists && $article->pdfUrl())
                    <span class="hint"><a href="{{ $article->pdfUrl() }}" target="_blank" rel="noopener">{{ __('Current file') }}</a> · {{ $article->pdf_file_id ? __('stored in Telegram') : __('stored on server') }}</span>
                @endif
            </div>
        </div>
        <div class="btn-row" style="margin-top:22px">
            <button class="btn btn-primary" type="submit"><x-icon name="check"/> {{ __('Save') }}</button>
            <a href="{{ route('admin.articles.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
        </div>
    </form>

    @if ($article->exists)
        <div>
            @php($zenodoOn = app(\App\Services\ZenodoService::class)->enabled())
            <form class="card card-pad panel" method="post" action="{{ route('admin.articles.zenodo', $article) }}" data-confirm="{{ __('Publish this article in Zenodo? The record and its DOI are permanent.') }}">
                @csrf
                <h2>Zenodo · DOI</h2>
                @if ($article->doi)
                    <p style="margin:0">DOI: <a href="{{ $article->doiUrl() }}" target="_blank" rel="noopener">{{ $article->doi }}</a></p>
                    @if ($article->zenodo_id)
                        <p class="small" style="margin:6px 0 0"><a href="https://zenodo.org/records/{{ $article->zenodo_id }}" target="_blank" rel="noopener">zenodo.org/records/{{ $article->zenodo_id }}</a></p>
                    @endif
                @elseif (! $zenodoOn)
                    <p class="muted small" style="margin:0">{{ __('Connect Zenodo in Panel → System to give articles a DOI.') }} <a href="{{ route('admin.system') }}#zenodo">{{ __('Open System') }}</a></p>
                @else
                    @if ($article->authorAgreedToZenodo())
                        <p class="small" style="margin:0 0 10px">{{ __('The author agreed to CC BY 4.0 and Zenodo when submitting.') }}</p>
                    @else
                        <label class="check small" style="margin:0 0 10px">
                            <input type="checkbox" name="consent" value="1" required>
                            <span>{{ __('The authors of this article agreed to CC BY 4.0 publication and deposit in Zenodo.') }}</span>
                        </label>
                    @endif
                    <button class="btn btn-primary btn-sm" type="submit" data-busy="{{ __('Depositing…') }}"><x-icon name="upload"/> {{ __('Deposit in Zenodo') }}</button>
                @endif
            </form>
            @php($poster = app(\App\Services\ChannelPoster::class))
            @if ($poster->ready())
                <form class="card card-pad panel" method="post" action="{{ route('admin.articles.channel', $article) }}" data-confirm="{{ __('Post this paper to the Telegram channel :channel?', ['channel' => $poster->chat()]) }}">
                    @csrf
                    <h2><x-icon name="telegram" style="width:18px;height:18px;vertical-align:-3px"/> {{ __('Telegram channel') }}</h2>
                    @if ($article->channel_posted_at)
                        <p class="small" style="margin:0 0 10px">{{ __('Posted') }}: {{ \Illuminate\Support\Carbon::parse($article->channel_posted_at)->timezone('Asia/Tashkent')->format('d.m.Y H:i') }}
                            @if ($article->channel_message_id) · <a href="https://t.me/{{ ltrim($poster->chat(), '@') }}/{{ $article->channel_message_id }}" target="_blank" rel="noopener">{{ __('Open the post') }}</a>@endif</p>
                    @else
                        <p class="muted small" style="margin:0 0 10px">{{ __('Not posted yet.') }}</p>
                    @endif
                    <button class="btn btn-ghost btn-sm" type="submit"><x-icon name="send"/> {{ $article->channel_posted_at ? __('Post again') : __('Post to the channel') }}</button>
                </form>
            @endif
            <form class="card card-pad panel" method="post" action="{{ route('admin.articles.certificate', $article) }}">
                @csrf
                <h2><x-icon name="award" style="width:18px;height:18px;vertical-align:-3px"/> {{ __('Publication certificate') }}</h2>
                <p class="muted small">{{ __('Works for papers published before online submission too. The certificate has a QR code linking to its verification page.') }}</p>
                @if ($certificate)
                    <p class="small">{{ __('Certificate number') }}: <code>{{ $certificate->code }}</code></p>
                    <div class="btn-row" style="margin-bottom:14px">
                        <a href="{{ route('certificate.download', $certificate) }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm"><x-icon name="download"/> PDF</a>
                        <a href="{{ route('certificate.show', $certificate) }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm"><x-icon name="qr"/> {{ __('Verification page') }}</a>
                    </div>
                @endif
                <div style="display:grid;gap:10px">
                    <div class="field">
                        <label>{{ __('Language') }}</label>
                        <select class="select" name="locale">
                            @foreach (\App\Support\Locale::LABELS as $code => $label)
                                <option value="{{ $code }}" @selected(optional($certificate)->locale === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label>{{ __('Send to e-mail') }} <span class="opt">{{ __('optional') }}</span></label>
                        <input class="input" type="email" name="email" placeholder="author@example.com">
                    </div>
                    <button class="btn btn-dark">{{ $certificate ? __('Send certificate') : __('Create certificate') }}</button>
                </div>
            </form>
            <div class="card card-pad panel">
                <h2>{{ __('Permanent link') }}</h2>
                <div class="cite" style="word-break:break-all">{{ lroute('maqola', $article->id) }}</div>
            </div>
        </div>
    @endif
</div>
@endsection
