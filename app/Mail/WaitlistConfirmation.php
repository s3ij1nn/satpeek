<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WaitlistConfirmation extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $email,
        public readonly ?string $faucetpayEmail = null,
        public readonly ?string $referralCode = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're on the SatPeek waitlist",
            tags: ['waitlist', 'confirmation'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.waitlist',
            text: 'emails.waitlist-text',
            with: [
                'email' => $this->email,
                'faucetpayEmail' => $this->faucetpayEmail,
                'referralCode' => $this->referralCode,
                'minWithdrawSat' => (int) config('satpeek.faucetpay.min_withdraw_sat', 1000),
                'referralCommissionPct' => (int) config('satpeek.referral.commission_pct', 10),
            ],
        );
    }
}
