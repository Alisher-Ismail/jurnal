<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Maqola;
use App\Models\Paper;
use App\Services\CertificateService;
use App\Services\FileVault;
use App\Services\PaperService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PaperController extends Controller
{
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), Paper::STATUSES, true) ? $request->query('status') : null;
        $q = trim((string) $request->query('q'));

        $papers = Paper::query()
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '%_').'%';
                $query->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('authors', 'like', $like)
                    ->orWhere('code', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when(\App\Support\SchemaState::originalityReady(), fn ($q) => $q->with('check'))
            ->latest()->paginate(20)->withQueryString();
        if (! \App\Support\SchemaState::originalityReady()) {
            $papers->getCollection()->each(fn ($p) => $p->setRelation('check', null));
        }

        $counts = Paper::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('admin.papers.index', compact('papers', 'status', 'q', 'counts'));
    }

    public function show(Paper $paper)
    {
        $paper->load(['article', 'issue', 'certificates']);
        \App\Support\SchemaState::originalityReady() ? $paper->load('check') : $paper->setRelation('check', null);

        return view('admin.papers.show', [
            'paper' => $paper,
            'issues' => Maqola::latest('id')->get(),
            'paperIsPdf' => ! $paper->hasFile() || app(PaperService::class)->paperIsPdf($paper),
        ]);
    }

    public function review(Paper $paper, PaperService $papers)
    {
        $papers->markUnderReview($paper);

        return back()->with('success', __('Marked as under review. The author has been notified.'));
    }

    public function accept(Request $request, Paper $paper, PaperService $papers)
    {
        $data = $request->validate([
            'issue_id' => 'required|exists:maqola,id',
            'title' => 'required|string|max:255',
            'authors' => 'required|string|max:255',
            'keywords' => 'nullable|string|max:255',
            'abstract' => 'nullable|string',
            'note' => 'nullable|string|max:2000',
            // Optional: replaces the submitted PDF or the automatic conversion of a Word manuscript.
            'pdf' => 'nullable|file|mimes:pdf|max:'.config('journal.max_admin_upload_kb'),
        ]);

        if (! $paper->isOpen()) {
            return back()->with('error', __('This paper already has a decision.'));
        }
        if (! $request->hasFile('pdf') && $paper->hasFile() && ! $papers->paperIsPdf($paper) && ! $papers->convertedPdf($paper)) {
            return back()->withInput()->with('error', __('The Word file could not be converted automatically. Please upload the final PDF.'));
        }

        $article = $papers->accept($paper, Maqola::findOrFail($data['issue_id']), $data['note'] ?? null, $data, $request->file('pdf'));
        $message = $paper->fresh()->certificateAllowed()
            ? __('Published as article #:id. The author received the link and certificate.', ['id' => $article->id])
            : __('Published as article #:id. The author received the link and the payment details; the certificate follows the confirmed payment.', ['id' => $article->id]);

        // The author agreed to CC BY 4.0 + Zenodo when submitting: deposit now to get the DOI.
        $zenodo = app(\App\Services\ZenodoService::class);
        if ($paper->license_agreed_at && $zenodo->enabled() && \App\Services\ZenodoService::settings()['auto']) {
            try {
                $message .= ' '.__('Deposited in Zenodo, DOI: :doi', ['doi' => $zenodo->deposit($article->fresh())]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Zenodo deposit failed', ['article' => $article->id, 'error' => $e->getMessage()]);

                return redirect()->route('admin.papers.show', $paper)->with('success', $message)
                    ->with('error', __('Zenodo deposit failed: :error. Try again from the article page.', ['error' => $e->getMessage()]));
            }
        }

        return redirect()->route('admin.papers.show', $paper)->with('success', $message);
    }

    public function reject(Request $request, Paper $paper, PaperService $papers)
    {
        $data = $request->validate(['note' => 'nullable|string|max:2000']);
        if (! $paper->isOpen()) {
            return back()->with('error', __('This paper already has a decision.'));
        }

        $papers->reject($paper, $data['note'] ?? null);

        return back()->with('success', __('Paper rejected. The author has been notified.'));
    }

    /** Administrator: the author has paid. The publication certificate is sent now (or on acceptance). */
    public function payment(Request $request, Paper $paper, PaperService $papers)
    {
        if ($paper->status === Paper::REJECTED) {
            return back()->with('error', __('This paper was rejected.'));
        }
        $certificate = $papers->confirmPayment($paper, $request->user()->name);

        return back()->with('success', $certificate
            ? __('Payment confirmed. The publication certificate was sent to the author.')
            : __('Payment confirmed. The certificate will be sent when the paper is accepted.'));
    }

    /** Editor's review (taqriz) with a recommendation; kept for the editors, not sent to the author. */
    public function saveReview(Request $request, Paper $paper)
    {
        $data = $request->validate([
            'review_text' => 'required|string|max:20000',
            'review_decision' => 'required|in:'.implode(',', Paper::REVIEW_DECISIONS),
        ]);
        $paper->update($data + ['review_by' => $request->user()->name, 'review_at' => now()]);

        return back()->with('success', __('Review saved.'));
    }

    public function resend(Paper $paper, PaperService $papers, CertificateService $certificates)
    {
        if (Paper::certificateNeedsPayment()) {
            if (! $paper->certificateAllowed()) {
                return back()->with('error', __('The certificate is issued after the paper is accepted and the payment is confirmed.'));
            }

            $papers->notifyAuthor($paper, $paper->paymentRequired() ? 'paid' : 'accepted', $certificates->forArticle($paper->article, $paper->locale, $paper));

            return back()->with('success', __('Certificate sent again.'));
        }
        if ($paper->status === Paper::ACCEPTED && $paper->article) {
            $papers->notifyAuthor($paper, 'accepted', $certificates->forArticle($paper->article, $paper->locale, $paper));
        } else {
            $papers->notifyAuthor($paper, 'submitted', $certificates->forSubmission($paper));
        }

        return back()->with('success', __('Certificate sent again.'));
    }

    /** Run (or re-run) the originality check now. */
    public function check(Paper $paper, \App\Services\Originality\OriginalityService $originality)
    {
        $check = $originality->run($paper, false);

        return back()->with($check->status === 'done' ? 'success' : 'error', $originality->summary($check, app()->getLocale()));
    }

    /** The editor verified the authorship: issue the held submission certificate. */
    public function release(Paper $paper, \App\Services\Originality\OriginalityService $originality)
    {
        $originality->releaseCertificate($paper);

        return back()->with('success', __('Certificate issued and sent to the author.'));
    }

    /** Save the result of a manual check on an external service such as antiplag.uz. */
    public function external(Request $request, Paper $paper)
    {
        $data = $request->validate([
            'external_service' => 'required|string|max:60',
            'external_similarity' => 'nullable|numeric|min:0|max:100',
            'external_ai' => 'nullable|numeric|min:0|max:100',
            'external_note' => 'nullable|string|max:1000',
            'report' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:20480',
        ]);

        $check = \App\Models\PaperCheck::firstOrCreate(['paper_id' => $paper->id], ['status' => 'pending']);
        if ($request->hasFile('report')) {
            if ($check->external_report) {
                Storage::disk('local')->delete($check->external_report);
            }
            $data['external_report'] = $request->file('report')->store('papers/external', 'local');
        }
        unset($data['report']);
        $check->fill($data + ['external_by' => $request->user()->name, 'external_at' => now()])->save();

        return back()->with('success', __('Saved.'));
    }

    public function externalReport(Paper $paper)
    {
        $path = optional($paper->check)->external_report;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $paper->code.'-'.basename($path));
    }

    /** The PDF that publishing a Word manuscript will produce. */
    public function preview(Paper $paper, PaperService $papers)
    {
        $pdf = $papers->convertedPdf($paper);
        if (! $pdf) {
            return back()->with('error', __('The Word file could not be converted automatically. Please upload the final PDF.'));
        }

        return response()->file($pdf, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$paper->code.'-preview.pdf"']);
    }

    public function file(Paper $paper, FileVault $vault)
    {
        return $vault->download($paper->file_id, $paper->file_path, $paper->file_name ?: $paper->code.'.pdf', 'local');
    }

    public function destroy(Paper $paper)
    {
        if ($paper->file_path) {
            Storage::disk('local')->delete($paper->file_path);
        }
        $paper->certificates()->where('type', Certificate::SUBMISSION)->delete();
        $paper->delete();

        return redirect()->route('admin.papers.index')->with('success', __('Deleted.'));
    }
}
