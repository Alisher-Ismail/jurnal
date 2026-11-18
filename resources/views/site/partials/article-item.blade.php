{{-- One article row. Expects $article (with ->issue loaded when $showIssue). --}}
<article class="article-item">
    <div>
        <h3><a href="{{ lroute('maqola', $article->id) }}">{{ $article->title }}</a></h3>
        @if ($article->authorList())
            <div class="authors">
                @foreach ($article->authorList() as $name)
                    <span><a href="{{ lroute('maqolaauthor', $name) }}">{{ $name }}</a>@if (! $loop->last),@endif</span>
                @endforeach
            </div>
        @endif
        <div class="meta-row">
            @if (($showIssue ?? false) && $article->issue)
                <span><x-icon name="book"/> <a href="{{ lroute('maqolavolume', $article->issue->id) }}">{{ $article->issue->volume }}: {{ \Illuminate\Support\Str::limit($article->issue->title, 90) }}</a></span>
            @endif
            @if ($article->publishedAt())
                <span><x-icon name="calendar"/> {{ $article->publishedAt()->format('d.m.Y') }}</span>
            @endif
        </div>
    </div>
    <div class="actions">
        <a class="btn btn-ghost btn-sm" href="{{ lroute('maqola', $article->id) }}">{{ __('Read') }}</a>
        @if ($article->pdfUrl())
            <a class="btn btn-ghost btn-sm" href="{{ $article->pdfUrl() }}" target="_blank" rel="noopener" aria-label="PDF"><x-icon name="download"/> PDF</a>
        @endif
    </div>
</article>
