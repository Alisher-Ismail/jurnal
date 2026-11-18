@extends('layouts.admin')

@section('title', __('Articles'))

@section('actions')
    <a href="{{ route('admin.articles.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus"/> {{ __('Add article') }}</a>
@endsection

@section('content')
<form class="toolbar" method="get">
    <select class="select" name="issue" onchange="this.form.submit()">
        <option value="">{{ __('All issues') }}</option>
        @foreach ($issues as $issue)
            <option value="{{ $issue->id }}" @selected((string) $issueId === (string) $issue->id)>{{ $issue->title }}</option>
        @endforeach
    </select>
    <select class="select" name="sort" onchange="this.form.submit()">
        <option value="">{{ __('Newest first') }}</option>
        <option value="views" @selected($sort === 'views')>{{ __('Most viewed') }}</option>
        <option value="downloads" @selected($sort === 'downloads')>{{ __('Most downloaded') }}</option>
        <option value="citations" @selected($sort === 'citations')>{{ __('Most cited') }}</option>
    </select>
    <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Title, author, keyword') }}">
    <button class="btn btn-ghost btn-sm" style="height:40px" type="submit"><x-icon name="search"/></button>
</form>

<div class="card">
    @if ($articles->isEmpty())
        <div class="empty"><x-icon name="file"/><h3>{{ __('Nothing here') }}</h3></div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>{{ __('Article') }}</th><th>{{ __('Issue') }}</th><th>PDF</th><th class="num" title="{{ __('Views') }}"><x-icon name="eye" style="width:15px;height:15px"/></th><th class="num" title="{{ __('Downloads') }}"><x-icon name="download" style="width:15px;height:15px"/></th><th class="num" title="{{ __('Citations') }}"><x-icon name="book" style="width:15px;height:15px"/></th><th>{{ __('Date') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($articles as $article)
                    <tr>
                        <td class="num">{{ $article->id }}</td>
                        <td class="title-cell"><a href="{{ route('admin.articles.edit', $article) }}" style="color:inherit">{{ \Illuminate\Support\Str::limit($article->title, 90) }}</a><small>{{ \Illuminate\Support\Str::limit($article->author, 80) }}</small></td>
                        <td class="small">{{ \Illuminate\Support\Str::limit(optional($article->issue)->title, 40) ?: '—' }}</td>
                        <td>@if ($article->pdfMissing())<span class="badge badge-rejected">{{ $article->pdfProblem() === 'not_pdf' ? __('Not a PDF') : __('Missing') }}</span>@elseif ($article->pdfUrl())<a href="{{ $article->pdfUrl() }}" target="_blank" rel="noopener" class="tag {{ $article->pdf_file_id ? 'tag-brand' : '' }}">{{ $article->pdf_file_id ? 'Telegram' : __('Server') }}</a>@else — @endif</td>
                        <td class="num" title="{{ __('On this website: :site · on Zenodo: :zenodo', ['site' => (int) $article->views_count, 'zenodo' => (int) $article->zenodo_views]) }}">{{ (int) $article->views_count + (int) $article->zenodo_views }}</td>
                        <td class="num" title="{{ __('On this website: :site · on Zenodo: :zenodo', ['site' => (int) $article->downloads_count, 'zenodo' => (int) $article->zenodo_downloads]) }}">{{ (int) $article->downloads_count + (int) $article->zenodo_downloads }}</td>
                        <td class="num" @if ($article->citations) style="color:var(--ink);font-weight:600" @endif>{{ (int) $article->citations }}</td>
                        <td class="num">{{ optional($article->created_at)->format('d.m.Y') }}</td>
                        <td>
                            <div class="row-actions">
                                <a class="icon-btn" href="{{ lroute('maqola', $article->id) }}" target="_blank" rel="noopener" title="{{ __('Public page') }}"><x-icon name="external"/></a>
                                <a class="icon-btn" href="{{ route('admin.articles.edit', $article) }}" title="{{ __('Edit') }}"><x-icon name="edit"/></a>
                                <form class="inline" method="post" action="{{ route('admin.articles.destroy', $article) }}" data-confirm="{{ __('Delete this article?') }}">
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
{{ $articles->links() }}
@endsection
