@extends('layouts.admin')

@section('title', __('Dashboard'))

@section('content')
<div class="kpis">
    <a href="{{ route('admin.papers.index', ['status' => 'submitted']) }}" class="card kpi {{ $counts['pending'] ? 'hot' : '' }}">
        <span><x-icon name="inbox"/> {{ __('Awaiting decision') }}</span><b>{{ $counts['pending'] }}</b>
    </a>
    <a href="{{ route('admin.papers.index') }}" class="card kpi"><span><x-icon name="upload"/> {{ __('All submissions') }}</span><b>{{ $counts['papers'] }}</b></a>
    <a href="{{ route('admin.articles.index') }}" class="card kpi"><span><x-icon name="file"/> {{ __('Articles') }}</span><b>{{ $counts['articles'] }}</b></a>
    <a href="{{ route('admin.issues.index') }}" class="card kpi"><span><x-icon name="book"/> {{ __('Issues') }}</span><b>{{ $counts['issues'] }}</b></a>
    <div class="card kpi"><span><x-icon name="award"/> {{ __('Certificates') }}</span><b>{{ $counts['certificates'] }}</b></div>
</div>

<div class="panel-grid">
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:18px 20px 6px">
            <h2 style="font:600 16px var(--sans);margin:0">{{ __('Latest submissions') }}</h2>
            <a href="{{ route('admin.papers.index') }}" class="btn btn-ghost btn-sm">{{ __('All') }} <x-icon name="arrow-right"/></a>
        </div>
        @if ($recent->isEmpty())
            <div class="empty"><x-icon name="inbox"/><p>{{ __('No submissions yet. Share the submission page or the bot with authors.') }}</p></div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                    @foreach ($recent as $paper)
                        <tr>
                            <td class="title-cell"><a href="{{ route('admin.papers.show', $paper) }}" style="color:inherit">{{ \Illuminate\Support\Str::limit($paper->title, 80) }}</a><small>{{ \Illuminate\Support\Str::limit($paper->authors, 70) }}</small></td>
                            <td><span class="tag">{{ $paper->source === 'telegram' ? 'Telegram' : __('Website') }}</span></td>
                            <td><span class="badge badge-{{ $paper->status }}">{{ __('status.'.$paper->status) }}</span></td>
                            <td class="num">{{ $paper->created_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div>
        <div class="card card-pad panel">
            <h2>{{ __('Telegram integration') }}</h2>
            <div style="display:grid;gap:10px">
                <span @class(['status-dot', 'on' => $telegram['bot'], 'off' => ! $telegram['bot']])>{{ __('Bot token') }}{{ $telegram['username'] ? ' · @'.ltrim($telegram['username'], '@') : '' }}</span>
                <span @class(['status-dot', 'on' => $telegram['storage'], 'off' => ! $telegram['storage']])>{{ __('File storage channel') }}</span>
                <span @class(['status-dot', 'on' => $telegram['admins'] > 0, 'off' => $telegram['admins'] === 0])>{{ __('Editor chats') }}: {{ $telegram['admins'] }}</span>
            </div>
            @if (! $telegram['storage'])
                <p class="muted small" style="margin:14px 0 0">{{ __('Until a storage channel is configured, files are kept on the server disk. See README for setup.') }}</p>
            @endif
        </div>
        <div class="card card-pad panel">
            <h2>{{ __('Quick actions') }}</h2>
            <div style="display:grid;gap:8px">
                <a href="{{ route('admin.articles.create') }}" class="btn btn-ghost"><x-icon name="plus"/> {{ __('Add article') }}</a>
                <a href="{{ route('admin.issues.create') }}" class="btn btn-ghost"><x-icon name="plus"/> {{ __('Add issue') }}</a>
                <a href="{{ route('admin.pages.index') }}" class="btn btn-ghost"><x-icon name="edit"/> {{ __('Edit pages') }}</a>
            </div>
        </div>
    </div>
</div>
@endsection
