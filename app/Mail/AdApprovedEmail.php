<?php

namespace App\Mail;

use App\Models\PtcAd;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdApprovedEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly PtcAd $ad) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your SatPeek campaign is now live',
            tags: ['advertise', 'approved'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ad-approved',
            text: 'emails.ad-approved-text',
            with: ['ad' => $this->ad],
        );
    }
}
