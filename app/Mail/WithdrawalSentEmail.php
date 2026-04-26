<?php

namespace App\Mail;

use App\Models\Withdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WithdrawalSentEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Withdrawal $withdrawal) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your SatPeek payout has been sent',
            tags: ['withdrawal', 'sent'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.withdrawal-sent',
            text: 'emails.withdrawal-sent-text',
            with: ['withdrawal' => $this->withdrawal],
        );
    }
}
