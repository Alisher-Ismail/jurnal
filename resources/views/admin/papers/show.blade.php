@extends('layouts.admin')

@section('title', $paper->code)

@section('actions')
    <a href="{{ route('papers.show', $paper) }}" class="btn btn-ghost btn-sm" target="_blank" rel="noopener"><x-icon name="eye"/> {{ __('Author view') }}</a>
@endsection

@section('content')
@php($isAdmin = auth()->user()->isAdmin())
<div class="panel-grid">
    <div>
        <div class="card card-pad panel">
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
                <span class="badge badge-{{ $paper->status }}">{{ __('status.'.$paper->status) }}</span>
                <span class="tag">{{ $paper->source === 'telegram' ? 'Telegram' : __('Website') }}</span>
                <span class="muted small">{{ $paper->created_at->format('d.m.Y H:i') }}</span>
            </div>
            <h2 style="font:600 24px/1.3 var(--serif);margin-bottom:16px">{{ $paper->title }}</h2>
            <dl class="kv">
                <dt>{{ __('Authors') }}</dt><dd>{{ $paper->authors }}</dd>
                @if ($paper->affiliation)<dt>{{ __('Organisation') }}</dt><dd>{{ $paper->affiliation }}</dd>@endif
                <dt>{{ __('E-mail') }}</dt><dd>{!! $paper->email ? '<a href="mailto:'.e($paper->email).'">'.e($paper->email).'</a>' : '—' !!}</dd>
                @if ($paper->phone)<dt>{{ __('Phone') }}</dt><dd>{{ $paper->phone }}</dd>@endif
                <dt>Telegram</dt><dd>{{ $paper->telegram_username ? '@'.$paper->telegram_username : ($paper->telegram_chat_id ? 'ID '.$paper->telegram_chat_id : '—') }}</dd>
                <dt>{{ __('Language') }}</dt><dd>{{ \App\Support\Locale::LABELS[$paper->locale] ?? $paper->locale }}</dd>
                @if ($paper->keywords)<dt>{{ __('Keywords') }}</dt><dd>{{ $paper->keywords }}</dd>@endif
                <dt>{{ __('File') }}</dt>
                <dd>
                    @if ($paper->hasFile())
                        <a href="{{ route('admin.papers.file', $paper) }}" class="btn btn-ghost btn-sm"><x-icon name="download"/> {{ $paper->file_name }}</a>
                        <span class="muted small">{{ $paper->file_size ? number_format($paper->file_size / 1048576, 1).' MB' : '' }} · {{ $paper->file_id ? __('stored in Telegram') : __('stored on server') }}</span>
                    @else
                        —
                    @endif
                </dd>
            </dl>
            @if ($paper->abstract)
                <h2 style="margin-top:24px">{{ __('Abstract') }}</h2>
                <p style="white-space:pre-line;margin:0;font-family:var(--serif);font-size:16px;line-height:1.7">{{ $paper->abstract }}</p>
            @endif
        </div>

        @include('admin.papers._originality')

        {{-- Review (taqriz) written by an editor; stays in the panel. --}}
        <form class="card card-pad panel" method="post" action="{{ route('admin.papers.taqriz', $paper) }}">
            @csrf @method('PUT')
            <h2>📝 {{ __('Review') }} (taqriz)</h2>
            @if ($paper->review_at)
                <p class="muted small" style="margin:0 0 10px">{{ __('Last saved by :name on :date', ['name' => $paper->review_by, 'date' => $paper->review_at->format('d.m.Y H:i')]) }}</p>
            @endif
            <div class="field" style="margin-bottom:12px">
                <label>{{ __('Recommendation') }}</label>
                <select class="select" name="review_decision" required>
                    @foreach (\App\Models\Paper::REVIEW_DECISIONS as $decision)
                        <option value="{{ $decision }}" @selected(old('review_decision', $paper->review_decision) === $decision)>{{ __('review.'.$decision) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="margin-bottom:12px">
                <label>{{ __('Review text') }}</label>
                <textarea class="textarea" name="review_text" style="min-height:160px" required maxlength="20000">{{ old('review_text', $paper->review_text) }}</textarea>
            </div>
            <button class="btn btn-ghost"><x-icon name="check"/> {{ __('Save review') }}</button>
        </form>

        @if ($paper->article)
            <div class="card card-pad panel">
                <h2>{{ __('Published') }}</h2>
                <p style="margin:0 0 12px">{{ __('Article #:id in :issue', ['id' => $paper->article->id, 'issue' => optional($paper->issue)->title]) }}</p>
                <div class="btn-row">
                    <a href="{{ lroute('maqola', $paper->article->id) }}" class="btn btn-ghost btn-sm" target="_blank" rel="noopener"><x-icon name="external"/> {{ __('Public page') }}</a>
                    <a href="{{ route('admin.articles.edit', $paper->article) }}" class="btn btn-ghost btn-sm"><x-icon name="edit"/> {{ __('Edit article') }}</a>
                </div>
            </div>
        @endif

        <div class="card card-pad panel">
            <h2>{{ __('License and Zenodo') }}</h2>
            @if ($paper->license_agreed_at)
                <p style="margin:0"><x-icon name="check" style="width:16px;height:16px;vertical-align:-3px;color:var(--ok, #1f9d55)"/> {{ __('The author agreed to CC BY 4.0 publication and deposit in Zenodo on :date.', ['date' => $paper->license_agreed_at->format('d.m.Y H:i')]) }}</p>
            @else
                <p class="muted" style="margin:0">{{ __('Submitted before the agreement was asked. Get the author’s consent before depositing in Zenodo.') }}</p>
            @endif
            @if ($paper->article?->doi)
                <p style="margin:8px 0 0">DOI: <a href="{{ $paper->article->doiUrl() }}" target="_blank" rel="noopener">{{ $paper->article->doi }}</a></p>
            @endif
        </div>
        @if ($paper->admin_note)
            <div class="card card-pad panel"><h2>{{ __('Editor comment') }}</h2><p style="margin:0">{{ $paper->admin_note }}</p></div>
        @endif
    </div>

    <div>
        @if (\App\Models\Paper::certificateNeedsPayment() && $paper->status !== 'rejected')
            <div class="card card-pad panel">
                <h2>💳 {{ __('Payment') }}</h2>
                @if (! $paper->paymentRequired())
                    <p style="margin:0">🎁 {{ __('Free issue — no payment required') }}</p>
                @elseif ($paper->isPaid())
                    <p style="margin:0"><x-icon name="check" style="width:16px;height:16px;vertical-align:-3px;color:var(--ok, #1f9d55)"/>
                        {{ __('Payment confirmed by :name on :date', ['name' => $paper->paid_by, 'date' => $paper->paid_at->format('d.m.Y H:i')]) }}</p>
                @else
                    <p class="muted small" style="margin:0 0 12px">{{ __('Not paid yet. The publication certificate is sent only after the payment is confirmed.') }}</p>
                    @if ($isAdmin)
                        <form method="post" action="{{ route('admin.papers.payment', $paper) }}" data-confirm="{{ __('Confirm that the author has paid? The publication certificate will be sent to the author.') }}">
                            @csrf
                            <button class="btn btn-primary" style="width:100%"><x-icon name="check"/> {{ __('Payment confirmed') }}</button>
                        </form>
                    @else
                        <p class="muted small" style="margin:0">{{ __('Only the administrator confirms payments.') }}</p>
                    @endif
                @endif
            </div>
        @endif

        @if ($paper->isOpen())
            @if ($paper->status === 'submitted')
                <form class="card card-pad panel" method="post" action="{{ route('admin.papers.review', $paper) }}">
                    @csrf
                    <h2>{{ __('Start review') }}</h2>
                    <p class="muted small">{{ __('The author is notified that the paper is under review.') }}</p>
                    <button class="btn btn-ghost" style="width:100%"><x-icon name="eye"/> {{ __('Mark as under review') }}</button>
                </form>
            @endif

            <form class="card card-pad panel" method="post" action="{{ route('admin.papers.accept', $paper) }}" enctype="multipart/form-data">
                @csrf
                <h2>✅ {{ __('Accept and publish') }}</h2>
                @if ($issues->isEmpty())
                    <p class="muted small">{{ __('Create an issue first in the admin panel.') }}</p>
                    <a href="{{ route('admin.issues.create') }}" class="btn btn-ghost" style="width:100%"><x-icon name="plus"/> {{ __('Add issue') }}</a>
                @else
                    <div style="display:grid;gap:12px">
                        <div class="field"><label>{{ __('Issue') }}</label>
                            <select class="select" name="issue_id" required>
                                @foreach ($issues as $issue)<option value="{{ $issue->id }}">{{ $issue->title }}{{ $issue->is_free ? ' · 🎁 '.__('Free') : '' }}</option>@endforeach
                            </select>
                        </div>
                        <div class="field"><label>{{ __('Title') }}</label><input class="input" name="title" value="{{ old('title', $paper->title) }}" required maxlength="255"></div>
                        <div class="field"><label>{{ __('Authors') }}</label><input class="input" name="authors" value="{{ old('authors', $paper->authors) }}" required maxlength="255"></div>
                        <div class="field"><label>{{ __('Keywords') }}</label><input class="input" name="keywords" value="{{ old('keywords', $paper->keywords) }}" maxlength="255"></div>
                        <div class="field"><label>{{ __('Abstract') }}</label><textarea class="textarea" name="abstract" style="min-height:110px">{{ old('abstract', $paper->abstract) }}</textarea></div>
                        <div class="field"><label>{{ __('Comment for the author') }} <span class="opt">{{ __('optional') }}</span></label><input class="input" name="note" maxlength="2000"></div>
                        <div class="field">
                            <label>{{ __('Final PDF') }} <span class="opt">{{ __('optional') }}</span></label>
                            @unless ($paperIsPdf)
                                <div class="alert alert-info" style="margin:0 0 6px;font-size:13.5px"><x-icon name="info"/>
                                    <span>{{ __('The submission is a Word file. It will be converted to PDF automatically.') }}
                                        <a href="{{ route('admin.papers.preview', $paper) }}" target="_blank" rel="noopener"><strong>{{ __('Preview the PDF') }}</strong></a></span>
                                </div>
                            @endunless
                            <input class="input" type="file" name="pdf" accept="application/pdf">
                            <span class="hint">{{ $paperIsPdf ? __('Leave empty to publish the submitted PDF.') : __('Upload a PDF only if you want to replace the automatic conversion.') }}</span>
                        </div>
                        <button class="btn btn-primary"><x-icon name="award"/> {{ __('Publish and send link') }}</button>
                        <span class="muted small">{{ \App\Models\Paper::certificateNeedsPayment() && ! $paper->isPaid()
                            ? __('The author receives the link and the payment details. The certificate is sent after the administrator confirms the payment.').' '.__('In a free issue the certificate is sent right away.')
                            : __('The author receives the link and a publication certificate.') }}</span>
                    </div>
                @endif
            </form>

            <form class="card card-pad panel" method="post" action="{{ route('admin.papers.reject', $paper) }}" data-confirm="{{ __('Reject this paper?') }}">
                @csrf
                <h2>❌ {{ __('Reject') }}</h2>
                <div class="field" style="margin-bottom:12px"><textarea class="textarea" name="note" style="min-height:90px" placeholder="{{ __('Comment for the author') }}" maxlength="2000"></textarea></div>
                <button class="btn btn-ghost" style="width:100%;color:var(--bad)">{{ __('Reject') }}</button>
            </form>
        @endif

        <div class="card card-pad panel">
            <h2>{{ __('Certificates') }}</h2>
            @forelse ($paper->certificates as $certificate)
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;border-top:1px solid var(--line-2)">
                    <span class="small"><strong>{{ $certificate->type === 'publication' ? __('Publication') : __('Submission') }}</strong><br><code class="muted">{{ $certificate->code }}</code></span>
                    <a href="{{ route('certificate.download', $certificate) }}" target="_blank" rel="noopener" class="icon-btn" title="PDF"><x-icon name="download"/></a>
                </div>
            @empty
                <p class="muted small">—</p>
            @endforelse
            <form method="post" action="{{ route('admin.papers.resend', $paper) }}" style="margin-top:12px">
                @csrf
                <button class="btn btn-ghost btn-sm" style="width:100%"><x-icon name="send"/> {{ __('Send certificate again') }}</button>
            </form>
        </div>

        <form method="post" action="{{ route('admin.papers.destroy', $paper) }}" data-confirm="{{ __('Delete this submission permanently?') }}" style="text-align:center">
            @csrf @method('DELETE')
            <button class="btn btn-sm" style="background:none;color:var(--bad)"><x-icon name="trash"/> {{ __('Delete submission') }}</button>
        </form>
    </div>
</div>
@endsection
