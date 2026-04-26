<?php

namespace App\Mail;

use App\Models\PtcAd;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdRejectedEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly PtcAd $ad) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SatPeek — your campaign was not approved',
            tags: ['advertise', 'rejected'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ad-rejected',
            text: 'emails.ad-rejected-text',
            with: ['ad' => $this->ad],
        );
    }
}
