@extends('layouts.admin')

@section('title', $issue->exists ? __('Edit issue') : __('Add issue'))

@section('content')
<form class="card card-pad" style="max-width:820px" method="post" enctype="multipart/form-data"
      action="{{ $issue->exists ? route('admin.issues.update', $issue) : route('admin.issues.store') }}">
    @csrf
    @if ($issue->exists) @method('PUT') @endif
    <div class="form-grid">
        <div class="field">
            <label for="volume">{{ __('Volume / number') }}</label>
            <input class="input" id="volume" name="volume" value="{{ old('volume', $issue->volume) }}" placeholder="Vol. 5 No. 7" required maxlength="255">
        </div>
        <div class="field">
            <label for="title">{{ __('Title') }}</label>
            <input class="input" id="title" name="title" value="{{ old('title', $issue->title) }}" placeholder="Vol. 5 No. 7 (2024): Science and Education" required maxlength="255">
        </div>
        <div class="field full">
            <label for="abstract">{{ __('Description') }} <span class="opt">{{ __('optional') }}</span></label>
            <textarea class="textarea" id="abstract" name="abstract">{{ old('abstract', $issue->abstract) }}</textarea>
        </div>
        <div class="field full">
            <label for="pdf">{{ __('Full issue PDF') }} <span class="opt">{{ $issue->hasFile() ? __('leave empty to keep the current file') : __('optional') }}</span></label>
            <input class="input" type="file" id="pdf" name="pdf" accept="application/pdf">
            @if ($issue->pdfUrl())
                <span class="hint"><a href="{{ $issue->pdfUrl() }}" target="_blank" rel="noopener">{{ __('Current file') }}</a></span>
            @endif
        </div>
        <div class="field full">
            <label style="display:flex;gap:10px;align-items:flex-start;font-weight:600;cursor:pointer">
                <input type="checkbox" name="is_free" value="1" @checked(old('is_free', $issue->is_free)) style="width:18px;height:18px;margin-top:2px">
                <span>🎁 {{ __('Free issue — no payment required') }}
                    <span class="hint" style="display:block;font-weight:400">{{ __('Papers published in this issue get the publication certificate right away, without a payment. While this is the newest issue, authors are told at submission that publication is free.') }}</span></span>
            </label>
        </div>
    </div>
    <div class="btn-row" style="margin-top:22px">
        <button class="btn btn-primary" type="submit"><x-icon name="check"/> {{ __('Save') }}</button>
        <a href="{{ route('admin.issues.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
