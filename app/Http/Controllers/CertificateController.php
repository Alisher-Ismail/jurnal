<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Services\CertificateService;

/** Public verification page that the QR code on every certificate points to. */
class CertificateController extends Controller
{
    public function show(Certificate $certificate)
    {
        $certificate->load(['paper', 'article.issue']);

        return view('site.certificate', compact('certificate'));
    }

    public function download(Certificate $certificate, CertificateService $service)
    {
        return response($service->pdf($certificate), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$certificate->fileName().'"',
        ]);
    }
}
