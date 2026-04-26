<?php

namespace App\Mail;

use App\Models\Withdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WithdrawalQueuedEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Withdrawal $withdrawal) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Withdrawal request received',
            tags: ['withdrawal', 'queued'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.withdrawal-queued',
            text: 'emails.withdrawal-queued-text',
            with: [
                'withdrawal' => $this->withdrawal,
                'requiresReview' => (bool) $this->withdrawal->requires_review,
            ],
        );
    }
}
