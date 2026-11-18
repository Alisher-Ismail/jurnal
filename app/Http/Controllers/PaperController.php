<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Paper;
use App\Services\PaperService;
use Illuminate\Http\Request;

/** Public paper submission from the website. */
class PaperController extends Controller
{
    public function store(Request $request, PaperService $papers)
    {
        // Honeypot: real visitors never see or fill this field.
        if (filled($request->input('website'))) {
            return redirect()->back();
        }

        $data = $request->validate([
            'title' => 'required|string|min:5|max:255',
            'authors' => 'required|string|min:3|max:255',
            'affiliation' => 'nullable|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:40',
            'abstract' => 'required|string|min:50|max:5000',
            'keywords' => 'nullable|string|max:255',
            'file' => 'required|file|mimes:'.implode(',', config('journal.paper_extensions')).'|max:'.config('journal.max_upload_kb'),
            'agree' => 'accepted',
            'license' => 'accepted',
        ]);

        $paper = $papers->submit(array_merge($data, [
            'locale' => app()->getLocale(),
            'source' => 'web',
            'submitter_ip' => $request->ip(),
            'license_agreed_at' => now(),
        ]), $request->file('file'));

        return redirect()->route('papers.show', $paper)->with('submitted', true);
    }

    /** One box for both tracking codes (ES-26-XXXXXX) and certificate codes. */
    public function track(Request $request)
    {
        $code = strtoupper(trim((string) $request->query('code')));

        if ($code !== '' && ($paper = Paper::where('code', $code)->first())) {
            return redirect()->route('papers.show', $paper);
        }
        if ($code !== '' && ($certificate = Certificate::where('code', $code)->first())) {
            return redirect()->route('certificate.show', $certificate);
        }

        return redirect()->to(lroute('submit').'#track')->with('track_error', __('Nothing was found for this code.'));
    }

    public function show(Paper $paper)
    {
        $paper->load(['article', 'submissionCertificate']);
        \App\Support\SchemaState::originalityReady() ? $paper->load('check') : $paper->setRelation('check', null);

        return view('site.paper', compact('paper'));
    }
}
