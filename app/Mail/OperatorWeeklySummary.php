<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Weekly digest of platform activity dispatched to admin users every
 * Monday morning. Pairs with the dashboard widgets — same data shape,
 * different delivery channel (push vs pull). Operators who don't
 * habitually open `/admin` still get the signal they need to react to
 * spikes in tier escalations or payout anomalies.
 *
 * The summary payload is computed by {@see \App\Services\WeeklySummaryBuilder}.
 */
class OperatorWeeklySummary extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $summary  output of WeeklySummaryBuilder::build()
     */
    public function __construct(public readonly array $summary) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SatPeek weekly summary',
            tags: ['operator', 'weekly-summary'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.operator-weekly-summary',
            text: 'emails.operator-weekly-summary-text',
            with: ['summary' => $this->summary],
        );
    }
}
