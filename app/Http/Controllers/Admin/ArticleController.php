<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\ArticleCertificateMail;
use App\Models\Article;
use App\Models\Author;
use App\Models\Certificate;
use App\Models\Maqola;
use App\Services\CertificateService;
use App\Services\FileVault;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
        $issueId = $request->query('issue');
        // Readers' numbers (site + Zenodo, citations), as shown on the article page.
        $sorts = [
            'views' => '(views_count + zenodo_views) DESC',
            'downloads' => '(downloads_count + zenodo_downloads) DESC',
            'citations' => 'citations DESC',
        ];
        $sort = array_key_exists((string) $request->query('sort'), $sorts) ? (string) $request->query('sort') : null;
        $articles = Article::with('issue')
            ->when($issueId, fn ($q) => $q->where('volume', $issueId))
            ->search($request->query('q'))
            ->when($sort, fn ($q) => $q->orderByRaw($sorts[$sort]))
            ->latest('id')->paginate(25)->withQueryString();

        return view('admin.articles.index', [
            'articles' => $articles,
            'issues' => Maqola::latest('id')->get(),
            'issueId' => $issueId,
            'sort' => $sort,
        ]);
    }

    public function create()
    {
        return $this->form(new Article(['volume' => optional(Maqola::latest('id')->first())->id]));
    }

    public function store(Request $request, FileVault $vault)
    {
        $article = new Article($this->validated($request, true));
        $this->attachPdf($request, $article, $vault);
        $article->save();
        $this->rememberAuthors($article);

        return redirect()->route('admin.articles.index')->with('success', __('Saved.'));
    }

    public function edit(Article $article)
    {
        return $this->form($article);
    }

    public function update(Request $request, Article $article, FileVault $vault)
    {
        $article->fill($this->validated($request, false));
        $this->attachPdf($request, $article, $vault);
        $article->save();
        $this->rememberAuthors($article);

        return redirect()->route('admin.articles.edit', $article)->with('success', __('Saved.'));
    }

    public function destroy(Article $article)
    {
        if ($article->pdf) {
            Storage::disk('public')->delete($article->pdf);
        }
        $article->delete();

        return redirect()->route('admin.articles.index')->with('success', __('Deleted.'));
    }

    /** Replace a Word file that was stored as the article "PDF" with its PDF conversion. */
    public function convert(Article $article, FileVault $vault)
    {
        $source = $vault->localPath($article->pdf, 'public');
        $target = storage_path('app/convert/article-'.$article->id.'.pdf');
        if (! $source || ! app(\App\Services\WordToPdf::class)->convert($source, $target)) {
            return back()->with('error', __('The Word file could not be converted automatically. Please upload the final PDF.'));
        }

        $stored = $vault->storeUpload(new \Illuminate\Http\UploadedFile($target, 'article-'.$article->id.'.pdf', 'application/pdf', null, true), '📄 '.e($article->title), 'public', 'pdfs');
        $article->update(['pdf_file_id' => $stored['file_id'], 'pdf' => $stored['path']]);
        @unlink($target);

        return back()->with('success', __('Converted to PDF.'));
    }

    /** Deposit the article in Zenodo (permanent record + DOI). Needs the authors' agreement. */
    public function zenodo(Request $request, Article $article, \App\Services\ZenodoService $zenodo)
    {
        if (! $article->authorAgreedToZenodo() && ! $request->boolean('consent')) {
            return back()->with('error', __('Confirm that the authors agreed to CC BY 4.0 and Zenodo first.'));
        }

        try {
            $doi = $zenodo->deposit($article);
        } catch (\Throwable $e) {
            return back()->with('error', __('Zenodo deposit failed: :error. Try again from the article page.', ['error' => $e->getMessage()]));
        }

        return back()->with('success', __('Deposited in Zenodo, DOI: :doi', ['doi' => $doi]));
    }

    /** Announce this article in the journal's Telegram channel now. */
    public function channel(Article $article, \App\Services\ChannelPoster $poster)
    {
        try {
            $poster->postArticle($article);
        } catch (\Throwable $e) {
            return back()->with('error', __('Could not post to the Telegram channel: :error', ['error' => $e->getMessage()]));
        }

        return back()->with('success', __('Posted to the Telegram channel :channel.', ['channel' => $poster->chat()]));
    }

    /** Publication certificate for any article, including ones published before online submission existed. */
    public function certificate(Request $request, Article $article, CertificateService $certificates)
    {
        $data = $request->validate([
            'email' => 'nullable|email',
            'locale' => ['required', Rule::in(Locale::all())],
        ]);

        // The publication certificate of an online submission is issued only once the administrator confirms the payment.
        if ($article->paper && ! $article->paper->certificateAllowed() && ! $request->user()->isAdmin()) {
            return back()->with('error', __('Your publication certificate will be sent as soon as the payment is confirmed.'));
        }

        $certificate = $certificates->forArticle($article, $data['locale'], $article->paper);
        $message = __('Certificate :code is ready.', ['code' => $certificate->code]);

        if (! empty($data['email'])) {
            Mail::to($data['email'])->locale($data['locale'])
                ->send(new ArticleCertificateMail($article, $certificate, $certificates->pdf($certificate)));
            $message .= ' '.__('E-mail sent.');
        }

        return back()->with('success', $message);
    }

    private function form(Article $article)
    {
        return view('admin.articles.form', [
            'article' => $article,
            'issues' => Maqola::latest('id')->get(),
            'authors' => Author::orderBy('name')->pluck('name'),
            'certificate' => $article->exists
                ? Certificate::where('article_id', $article->id)->where('type', Certificate::PUBLICATION)->first()
                : null,
        ]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'volume' => 'required|exists:maqola,id',
            'title' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'keywords' => 'nullable|string|max:255',
            'doi' => ['nullable', 'string', 'max:191', 'regex:#^(https?://(dx\.)?doi\.org/|doi:\s*)?10\.\d{4,9}/\S+$#i'],
            'abstract' => 'nullable|string',
            'pdf' => ($creating ? 'required' : 'nullable').'|file|mimes:pdf|max:'.config('journal.max_admin_upload_kb'),
        ]);

        return [
            'volume' => (string) $data['volume'],
            'title' => $data['title'],
            'author' => implode(', ', array_filter(array_map('trim', explode(',', $data['author'])))),
            'keywords' => $data['keywords'] ?? '',
            // Stored bare (10.x/…); a pasted https://doi.org/ link or "doi:" prefix is removed.
            'doi' => filled($data['doi'] ?? null) ? preg_replace('#^(https?://(dx\.)?doi\.org/|doi:\s*)#i', '', trim($data['doi'])) : null,
            'abstract' => $data['abstract'] ?? '',
        ];
    }

    private function attachPdf(Request $request, Article $article, FileVault $vault): void
    {
        if (! $request->hasFile('pdf')) {
            return;
        }
        $stored = $vault->storeUpload($request->file('pdf'), '📄 '.e($request->input('title')), 'public', 'pdfs');
        if ($article->pdf && $stored['path'] !== $article->pdf) {
            Storage::disk('public')->delete($article->pdf);
        }
        $article->pdf_file_id = $stored['file_id'];
        $article->pdf = $stored['path'];
    }

    private function rememberAuthors(Article $article): void
    {
        foreach ($article->authorList() as $name) {
            Author::firstOrCreate(['name' => $name]);
        }
    }
}
