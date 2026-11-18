@extends('layouts.admin')

@section('title', __('Issues'))

@section('actions')
    <a href="{{ route('admin.issues.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus"/> {{ __('Add issue') }}</a>
@endsection

@section('content')
<div class="card">
    @if ($issues->isEmpty())
        <div class="empty"><x-icon name="book"/><h3>{{ __('No issues yet') }}</h3><a href="{{ route('admin.issues.create') }}" class="btn btn-primary btn-sm">{{ __('Add issue') }}</a></div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>{{ __('Title') }}</th><th>{{ __('Volume') }}</th><th>{{ __('Articles') }}</th><th>PDF</th><th>{{ __('Date') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($issues as $issue)
                    <tr>
                        <td class="num">{{ $issue->id }}</td>
                        <td class="title-cell">{{ $issue->title }}@if ($issue->is_free) <span class="tag tag-brand">🎁 {{ __('Free') }}</span>@endif</td>
                        <td>{{ $issue->volume }}</td>
                        <td><a href="{{ route('admin.articles.index', ['issue' => $issue->id]) }}">{{ $issue->articles_count }}</a></td>
                        <td>@if ($issue->pdfMissing())<span class="badge badge-rejected">{{ $issue->pdfProblem() === 'not_pdf' ? __('Not a PDF') : __('Missing') }}</span>@elseif ($issue->pdfUrl())<a href="{{ $issue->pdfUrl() }}" target="_blank" rel="noopener" class="tag {{ $issue->pdf_file_id ? 'tag-brand' : '' }}">{{ $issue->pdf_file_id ? 'Telegram' : __('Server') }}</a>@else — @endif</td>
                        <td class="num">{{ optional($issue->created_at)->format('d.m.Y') }}</td>
                        <td>
                            <div class="row-actions">
                                <a class="icon-btn" href="{{ lroute('maqolavolume', $issue->id) }}" target="_blank" rel="noopener" title="{{ __('Public page') }}"><x-icon name="external"/></a>
                                <a class="icon-btn" href="{{ route('admin.issues.edit', $issue) }}" title="{{ __('Edit') }}"><x-icon name="edit"/></a>
                                @if ($issue->articles_count)
                                    <form class="inline" method="post" action="{{ route('admin.issues.channel', $issue) }}" data-confirm="{{ __('Announce this issue (:count papers) in the Telegram channel?', ['count' => $issue->articles_count]) }}">
                                        @csrf
                                        <button class="icon-btn" title="{{ __('Announce in the Telegram channel') }}"><x-icon name="telegram"/></button>
                                    </form>
                                @endif
                                <form class="inline" method="post" action="{{ route('admin.issues.destroy', $issue) }}" data-confirm="{{ __('Delete this issue?') }}">
                                    @csrf @method('DELETE')
                                    <button class="icon-btn danger" title="{{ __('Delete') }}"><x-icon name="trash"/></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
