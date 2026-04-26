<?php

namespace App\Mail;

use App\Models\PtcAd;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdSubmittedEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly PtcAd $ad) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->ad->status === 'approved'
                ? 'Your SatPeek campaign is live'
                : 'Your SatPeek campaign is in review',
            tags: ['advertise', 'submitted'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ad-submitted',
            text: 'emails.ad-submitted-text',
            with: ['ad' => $this->ad],
        );
    }
}
