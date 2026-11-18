<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Author;
use App\Models\Maqola;
use App\Services\FileVault;
use App\Services\PdfSplitter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Journal issues (stored in the legacy "maqola" table). */
class IssueController extends Controller
{
    public function index()
    {
        return view('admin.issues.index', ['issues' => Maqola::withCount('articles')->latest('created_at')->latest('id')->get()]);
    }

    public function create()
    {
        return view('admin.issues.form', ['issue' => new Maqola]);
    }

    public function store(Request $request, FileVault $vault)
    {
        $issue = new Maqola($this->validated($request));
        $this->attachPdf($request, $issue, $vault);
        $issue->save();

        return redirect()->route('admin.issues.index')->with('success', __('Saved.'));
    }

    public function edit(Maqola $issue)
    {
        return view('admin.issues.form', compact('issue'));
    }

    public function update(Request $request, Maqola $issue, FileVault $vault)
    {
        $issue->fill($this->validated($request));
        $this->attachPdf($request, $issue, $vault);
        $issue->save();

        return redirect()->route('admin.issues.index')->with('success', __('Saved.'));
    }

    public function destroy(Maqola $issue)
    {
        if ($issue->articles()->exists()) {
            return back()->with('error', __('Move or delete the articles of this issue first.'));
        }
        if ($issue->pdf) {
            Storage::disk('public')->delete($issue->pdf);
        }
        $issue->delete();

        return back()->with('success', __('Deleted.'));
    }

    /**
     * Publish the papers of a proceedings issue as separate articles: each item names a page
     * range of the issue PDF, which is copied into its own PDF. Called in small batches (JSON);
     * an article whose title already exists in this issue is skipped, so a batch can be re-sent.
     */
    public function importArticles(Request $request, Maqola $issue, FileVault $vault, PdfSplitter $splitter)
    {
        $data = $request->validate([
            'items' => 'required|array|max:40',
            'items.*.title' => 'required|string|max:255',
            'items.*.author' => 'required|string|max:255',
            'items.*.keywords' => 'nullable|string|max:255',
            'items.*.abstract' => 'nullable|string|max:5000',
            'items.*.from' => 'required|integer|min:1',
            'items.*.to' => 'required|integer|min:1',
        ]);

        $source = $issue->pdf_file_id ? $vault->telegramCopy($issue->pdf_file_id) : null;
        $source = $source ?: $vault->localPath($issue->pdf, 'public');
        if (! $source || FileVault::detectExtension($source) !== 'pdf') {
            return response()->json(['error' => __('The PDF of this issue was not found.')], 422);
        }

        $report = ['created' => [], 'skipped' => [], 'failed' => []];
        foreach ($data['items'] as $item) {
            $title = trim(preg_replace('/\s+/u', ' ', $item['title']));
            $author = implode(', ', array_filter(array_map('trim', explode(',', $item['author']))));
            // Same title and same authors = already imported; two different papers may share a title.
            if (Article::where('volume', (string) $issue->id)->where('title', $title)->where('author', $author)->exists()) {
                $report['skipped'][] = $title;
                continue;
            }

            $path = sprintf('pdfs/articles/%d/%04d-%s.pdf', $issue->id, $item['from'], Str::slug(Str::limit($title, 60, '')) ?: 'article');
            try {
                $target = Storage::disk('public')->path($path);
                $count = $splitter->extract($source, (int) $item['from'], (int) $item['to'], $target);

                // Some PDFs list every image of the book on every page; a copy would then carry
                // the whole book. Refuse such files instead of filling the server.
                $fair = filesize($source) * $count / max(1, $splitter->lastTotalPages);
                if (filesize($target) > 4 * $fair + 2 * 1024 * 1024) {
                    throw new \RuntimeException(sprintf('Split copy is %.1f MB for %d pages (book shares resources across pages).', filesize($target) / 1048576, $count));
                }

                $article = Article::create([
                    'volume' => (string) $issue->id,
                    'title' => $title,
                    'author' => $author,
                    'keywords' => trim((string) ($item['keywords'] ?? '')),
                    'abstract' => trim((string) ($item['abstract'] ?? '')),
                    'pdf' => $path,
                ]);
                foreach ($article->authorList() as $name) {
                    Author::firstOrCreate(['name' => $name]);
                }
                $report['created'][] = ['id' => $article->id, 'title' => $title];
            } catch (Throwable $e) {
                Storage::disk('public')->delete($path);
                $report['failed'][] = ['title' => $title, 'error' => $e->getMessage()];
            }
        }

        return response()->json($report);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'volume' => 'required|string|max:255',
            'title' => 'required|string|max:255',
            'abstract' => 'nullable|string',
            'pdf' => 'nullable|file|mimes:pdf|max:'.config('journal.max_admin_upload_kb'),
        ]);

        return [
            'volume' => $data['volume'],
            'title' => $data['title'],
            'abstract' => (string) ($data['abstract'] ?? ''),
            'is_free' => $request->boolean('is_free'),
        ];
    }

    private function attachPdf(Request $request, Maqola $issue, FileVault $vault): void
    {
        if (! $request->hasFile('pdf')) {
            return;
        }
        $stored = $vault->storeUpload($request->file('pdf'), '📚 '.e($issue->title), 'public', 'pdfs');
        if ($issue->pdf && $stored['path'] !== $issue->pdf) {
            Storage::disk('public')->delete($issue->pdf);
        }
        $issue->pdf_file_id = $stored['file_id'];
        $issue->pdf = $stored['path'];
    }

    /** One Telegram channel post announcing the whole issue, with links to its articles. */
    public function channel(Maqola $issue, \App\Services\ChannelPoster $poster)
    {
        try {
            $poster->postIssue($issue);
        } catch (\Throwable $e) {
            return back()->with('error', __('Could not post to the Telegram channel: :error', ['error' => $e->getMessage()]));
        }

        return back()->with('success', __('Posted to the Telegram channel :channel.', ['channel' => $poster->chat()]));
    }
}
