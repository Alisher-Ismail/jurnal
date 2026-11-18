{{-- Generated issue cover. Expects $issue. --}}
<div class="cover">
    <div class="c-top"><span>{{ config('journal.short_name') }}</span><span>{{ optional($issue->created_at)->format('Y') }}</span></div>
    <x-mark class="c-mark"/>
    <div>
        <div class="c-title">{{ $issue->title }}</div>
        <div class="c-vol">{{ $issue->volume }}</div>
    </div>
</div>
