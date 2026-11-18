<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Certificate;
use App\Models\Paper;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class CertificateService
{
    public function forSubmission(Paper $paper): Certificate
    {
        return Certificate::firstOrCreate(
            ['paper_id' => $paper->id, 'type' => Certificate::SUBMISSION],
            ['recipient' => $paper->authors, 'title' => $paper->title, 'locale' => $paper->locale]
        );
    }

    /** One publication certificate per article, so re-requesting it never creates duplicates. */
    public function forArticle(Article $article, ?string $locale = null, ?Paper $paper = null): Certificate
    {
        return Certificate::firstOrCreate(
            ['article_id' => $article->id, 'type' => Certificate::PUBLICATION],
            [
                'paper_id' => $paper?->id,
                'recipient' => $article->author ?: '—',
                'title' => $article->title ?: '—',
                'locale' => $locale ?? $paper?->locale ?? 'uz',
            ]
        );
    }

    public function qr(string $data): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'eccLevel' => EccLevel::M,
            'scale' => 8,
            'addQuietzone' => true,
            'quietzoneSize' => 1,
            'outputBase64' => true,
        ]);

        return (new QRCode($options))->render($data);
    }

    /** A certificate as it would look now, from the latest published article (not saved). */
    public function previewPdf(string $type, string $locale): string
    {
        $article = Article::with('issue')->latest('id')->first();
        $certificate = new Certificate([
            'type' => $type === 'submission' ? Certificate::SUBMISSION : Certificate::PUBLICATION,
            'recipient' => $article?->author ?: 'Aliyev Vali Karimovich',
            'title' => $article?->title ?: 'Sample title of a scientific paper',
            'locale' => $locale,
            'issued_at' => now(),
        ]);
        $certificate->code = 'PREVIEW';
        $certificate->setRelation('paper', null);
        $certificate->setRelation('article', $type === 'submission' ? null : $article);

        return $this->pdf($certificate);
    }

    public function pdf(Certificate $certificate): string
    {
        $certificate->loadMissing(['paper', 'article.issue']);
        $locale = $certificate->locale ?: 'uz';

        return Pdf::loadView('certificates.certificate', [
            'certificate' => $certificate,
            'qr' => $this->qr($certificate->verifyUrl()),
            'issue' => $certificate->article?->issue ?? $certificate->paper?->issue,
            'locale' => $locale,
            't' => fn (string $key, array $replace = []) => __($key, $replace, $locale),
        ])->setPaper('a4', 'landscape')->setOption(['isFontSubsettingEnabled' => true])->output();
    }
}
