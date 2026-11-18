@extends('layouts.admin')

@php($meta = \App\Support\PageContent::PAGES[$page])

@section('title', __($meta['title']).' · '.\App\Support\Locale::LABELS[$locale])

@push('head')
    <link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
@endpush

@section('actions')
    <a href="{{ lroute($meta['route'], [], $locale) }}" class="btn btn-ghost btn-sm" target="_blank" rel="noopener"><x-icon name="external"/> {{ __('Public page') }}</a>
@endsection

@section('content')
<div class="filters" style="margin-bottom:16px">
    @foreach (\App\Support\Locale::LABELS as $code => $label)
        <a href="{{ route('admin.pages.edit', [$page, $code]) }}" @class(['on' => $code === $locale])>{{ $label }}</a>
    @endforeach
</div>

<form method="post" action="{{ route('admin.pages.update', [$page, $locale]) }}" id="page-form">
    @csrf @method('PUT')
    <input type="hidden" name="html" id="html">
    <div id="editor">{!! $html !!}</div>
    <div class="btn-row" style="margin-top:18px">
        <button class="btn btn-primary" type="submit"><x-icon name="check"/> {{ __('Save') }}</button>
        <a href="{{ route('admin.pages.index') }}" class="btn btn-ghost">{{ __('Back') }}</a>
    </div>
</form>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
    const quill = new Quill('#editor', {
        theme: 'snow',
        modules: {
            toolbar: [
                [{ header: [2, 3, false] }],
                ['bold', 'italic', 'underline', 'link'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                ['blockquote', { align: [] }, 'clean'],
            ],
        },
    });
    document.getElementById('page-form').addEventListener('submit', function () {
        document.getElementById('html').value = quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML;
    });
</script>
@endpush
