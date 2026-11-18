<?php

namespace App\Mail;

use App\Models\Certificate;
use App\Models\Paper;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaperUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Paper $paper,
        public string $event,
        public ?Certificate $certificate = null,
        public ?string $certificatePdf = null,
    ) {
    }

    public function build()
    {
        $subjects = [
            'submitted' => 'Your submission certificate',
            'received' => 'Your paper has been received',
            'held' => 'Your paper needs an additional check',
            'under_review' => 'Your paper is now under review.',
            'accepted' => 'Your paper has been accepted and published!',
            'rejected' => 'Decision on your paper',
            'received_pay' => 'Your paper has been received',
            'accepted_pay' => 'Your paper has been accepted and published!',
            'paid' => 'Your publication certificate',
        ];

        $mail = $this->subject(config('journal.short_name').' · '.__($subjects[$this->event] ?? 'Paper update'))
            ->view('emails.paper-update');

        if ($this->certificate && $this->certificatePdf) {
            $mail->attachData($this->certificatePdf, $this->certificate->fileName(), ['mime' => 'application/pdf']);
        }

        return $mail;
    }
}
