<?php

namespace App\Mail;

use App\Models\Paper;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ChannelInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Paper $paper)
    {
    }

    public function build()
    {
        return $this->subject(config('journal.short_name').' · '.__('Join our Telegram channel'))
            ->view('emails.channel-invite');
    }
}
