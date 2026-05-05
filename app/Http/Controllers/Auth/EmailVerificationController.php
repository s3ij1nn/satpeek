<?php

namespace App\Http\Controllers\Auth;

use App\Captcha\ChallengeVerifier;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly ChallengeVerifier $verifier,
    ) {}

    /** Show the "please verify" landing for authenticated-but-unverified users. */
    public function notice(Request $request)
    {
        if ($request->user()?->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        return view('auth.verify-email');
    }

    /** Resolve a clicked /email/verify/{id}/{hash} signed link. */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard')->with('status', 'Email already verified.');
        }
        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return redirect()->route('dashboard')->with('status', 'Email verified — your account is fully active.');
    }

    /**
     * Re-send the verification email on demand.
     *
     * Captcha-gated against inbox-bombing: a session alone (post-register
     * or post-login) is not enough to trigger a resend — the user must
     * solve a fresh trajectory captcha each time. Combined with the
     * `verification-send` rate limiter (1/min, 6/hr per user) on the
     * route, this rules out the "bot creates account, scripts the resend
     * button to spam target inbox" pattern. Non-AJAX form submission
     * stays supported (no-JS users get a normal redirect/back flow).
     */
    public function send(Request $request): RedirectResponse
    {
        if ($request->user()?->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'captcha_challenge_id' => ['required', 'string', 'max:64'],
            'captcha_points' => ['required', 'string', 'max:65536'],
        ]);

        $points = json_decode($validated['captcha_points'], true);
        if (! is_array($points) || $points === []) {
            return back()->withErrors(['captcha' => 'Captcha submission was empty. Please drag along the path and try again.']);
        }

        $result = $this->verifier->verify($request, $validated['captcha_challenge_id'], $points);
        if (! $result->passed) {
            return back()->withErrors(['captcha' => 'Captcha rejected ('.$result->reason.'). Please trace the moving target into the goal and try again.']);
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'Verification email re-sent. Check your inbox.');
    }
}
