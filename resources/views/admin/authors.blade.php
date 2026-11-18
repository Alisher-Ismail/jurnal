@extends('layouts.admin')

@section('title', __('Authors'))

@section('content')
<div class="panel-grid">
    <div class="card">
        @if ($authors->isEmpty())
            <div class="empty"><x-icon name="users"/><p>{{ __('Authors are added automatically when articles are saved.') }}</p></div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Name') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($authors as $author)
                        <tr>
                            <td>
                                <form method="post" action="{{ route('admin.authors.update', $author) }}" style="display:flex;gap:8px">
                                    @csrf @method('PUT')
                                    <input class="input" name="name" value="{{ $author->name }}" style="height:38px;padding:0 12px" required maxlength="255">
                                    <button class="icon-btn" title="{{ __('Save') }}" style="height:38px;width:38px"><x-icon name="check"/></button>
                                </form>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <a class="icon-btn" href="{{ lroute('maqolaauthor', $author->name) }}" target="_blank" rel="noopener" title="{{ __('Public page') }}"><x-icon name="external"/></a>
                                    <form class="inline" method="post" action="{{ route('admin.authors.destroy', $author) }}" data-confirm="{{ __('Delete this author?') }}">
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
    <form class="card card-pad panel" method="post" action="{{ route('admin.authors.store') }}">
        @csrf
        <h2>{{ __('Add author') }}</h2>
        <p class="muted small">{{ __('Names here are suggested when you type authors of an article.') }}</p>
        <div class="field" style="margin-bottom:12px"><input class="input" name="name" required maxlength="255" placeholder="{{ __('Full name') }}"></div>
        <button class="btn btn-primary" style="width:100%"><x-icon name="plus"/> {{ __('Add') }}</button>
    </form>
</div>
@endsection
