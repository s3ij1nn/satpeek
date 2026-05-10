<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Page-out mail when one or more hot-wallet monitors flip to `down`
 * (gap < 0 OR chain probe failed). Dispatched by
 * `HotWalletLowBalanceCommand` on a 15-minute cron with cache-backed
 * idempotency: the same alert isn't re-sent until the underlying
 * status returns to `ok` and then degrades again.
 *
 * The payload is the per-currency rows the runway probe already
 * builds — same shape weekly summary + dashboard widget consume.
 */
class HotWalletLowBalanceAlert extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $downRows
     */
    public function __construct(
        public readonly array $downRows,
    ) {}

    public function envelope(): Envelope
    {
        $codes = implode(', ', array_map(fn ($r) => (string) $r['code'], $this->downRows));

        return new Envelope(
            subject: "[SatPeek] Hot wallet low balance: {$codes}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.hot-wallet-low-balance',
            text: 'emails.hot-wallet-low-balance-text',
        );
    }
}
