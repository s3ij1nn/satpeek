<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class WelcomeEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to SatPeek — verify your email',
            tags: ['welcome', 'verify'],
        );
    }

    public function content(): Content
    {
        // Generate the same kind of signed URL Laravel's VerifyEmail notification uses,
        // so the link works against routes/web.php's email verification controller.
        $verifyUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $this->user->getKey(),
                'hash' => sha1($this->user->getEmailForVerification()),
            ],
        );

        return new Content(
            view: 'emails.welcome',
            text: 'emails.welcome-text',
            with: [
                'username' => $this->user->username,
                'email' => $this->user->email,
                'referralCode' => $this->user->referral_code,
                'verifyUrl' => $verifyUrl,
                'minWithdrawSat' => (int) config('satpeek.faucetpay.min_withdraw_sat', 1000),
                'referralCommissionPct' => (int) config('satpeek.referral.commission_pct', 10),
            ],
        );
    }
}
