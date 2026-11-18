@extends('layouts.admin')

@section('title', __('Submissions'))

@section('content')
<div class="toolbar">
    <div class="filters">
        <a href="{{ route('admin.papers.index', array_filter(['q' => $q])) }}" @class(['on' => ! $status])>{{ __('All') }} <b>{{ $counts->sum() }}</b></a>
        @foreach (\App\Models\Paper::STATUSES as $s)
            <a href="{{ route('admin.papers.index', array_filter(['status' => $s, 'q' => $q])) }}" @class(['on' => $status === $s])>{{ __('status.'.$s) }} <b>{{ $counts[$s] ?? 0 }}</b></a>
        @endforeach
    </div>
    <form class="grow" method="get" style="display:flex;gap:8px">
        @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
        <input class="input" type="search" name="q" value="{{ $q }}" placeholder="{{ __('Title, author, code, e-mail') }}">
        <button class="btn btn-ghost btn-sm" style="height:40px" type="submit"><x-icon name="search"/></button>
    </form>
</div>

<div class="card">
    @if ($papers->isEmpty())
        <div class="empty"><x-icon name="inbox"/><h3>{{ __('Nothing here') }}</h3><p>{{ __('New submissions from the website and the Telegram bot appear in this list.') }}</p></div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Paper') }}</th><th>{{ __('Code') }}</th><th>{{ __('Source') }}</th><th>{{ __('Status') }}</th><th>{{ __('Originality') }}</th><th>{{ __('Received') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($papers as $paper)
                    <tr>
                        <td class="title-cell"><a href="{{ route('admin.papers.show', $paper) }}" style="color:inherit">{{ \Illuminate\Support\Str::limit($paper->title, 90) }}</a><small>{{ \Illuminate\Support\Str::limit($paper->authors, 80) }}</small></td>
                        <td class="num"><code>{{ $paper->code }}</code></td>
                        <td><span class="tag">{{ $paper->source === 'telegram' ? 'Telegram' : __('Website') }}</span></td>
                        <td><span class="badge badge-{{ $paper->status }}">{{ __('status.'.$paper->status) }}</span></td>
                        <td style="white-space:nowrap">
                            @if ($paper->check && $paper->check->status === 'done')
                                @php($lv = ['low' => 'badge-accepted', 'medium' => 'badge-under_review', 'high' => 'badge-rejected'])
                                <span class="badge {{ $lv[$paper->check->similarityLevel()] }}" title="{{ __('Archive similarity') }}">{{ $paper->check->similarity }}%</span>
                                @if ($paper->check->conflictType() === 'mismatch' || $paper->check->conflictType() === 'web')
                                    <span class="badge badge-rejected" title="{{ __('Authorship conflict: this text matches work of other authors.') }}">⚠️ {{ __('Authorship') }}</span>
                                @endif
                                @if ($paper->check->ai_score !== null)
                                    <span class="badge {{ $lv[$paper->check->aiLevel()] }}" title="{{ __('AI-writing indicator') }}">AI {{ $paper->check->ai_score }}</span>
                                @endif
                            @elseif ($paper->check && $paper->check->status === 'failed')
                                <span class="muted small">{{ __('failed') }}</span>
                            @else
                                <span class="muted small">—</span>
                            @endif
                        </td>
                        <td class="num">{{ $paper->created_at->format('d.m.Y H:i') }}</td>
                        <td><div class="row-actions"><a class="btn btn-ghost btn-sm" href="{{ route('admin.papers.show', $paper) }}">{{ __('Open') }}</a></div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
{{ $papers->links() }}
@endsection
