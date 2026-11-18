<?php

namespace App\Mail;

use App\Models\Article;
use App\Models\Certificate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ArticleCertificateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Article $article, public Certificate $certificate, public string $certificatePdf)
    {
    }

    public function build()
    {
        return $this->subject(config('journal.short_name').' · '.__('Publication certificate'))
            ->view('emails.article-certificate')
            ->attachData($this->certificatePdf, $this->certificate->fileName(), ['mime' => 'application/pdf']);
    }
}
