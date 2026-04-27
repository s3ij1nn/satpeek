<?php

namespace App\Http\Controllers;

use App\Captcha\ChallengeVerifier;
use App\Mail\WaitlistConfirmation;
use App\Models\WaitlistSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WaitlistController extends Controller
{
    public function __construct(private readonly ChallengeVerifier $verifier) {}

    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        $isAjax = $request->expectsJson() || $request->wantsJson()
            || $request->header('X-Requested-With') === 'XMLHttpRequest';

        // Honeypot — bots tend to fill every input.
        if ($request->filled('website')) {
            return $this->ok(
                $request,
                $isAjax,
                email: (string) $request->input('email', ''),
                message: 'Thanks!',
                recentlyCreated: false,
            );
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:200'],
            'faucetpay_email' => ['nullable', 'email', 'max:200'],
            'referral_code' => ['nullable', 'string', 'max:16'],
            'source' => ['nullable', 'string', 'max:64'],
            'captcha_challenge_id' => ['required', 'string', 'max:64'],
            'captcha_points' => ['required', 'string', 'max:65536'],
        ]);

        $points = json_decode($validated['captcha_points'], true);
        if (! is_array($points) || empty($points)) {
            return $this->fail(
                $isAjax,
                reason: 'empty_points',
                message: 'Captcha submission was empty. Please drag along the path and try again.',
            );
        }

        $result = $this->verifier->verify(
            $request,
            $validated['captcha_challenge_id'],
            $points
        );
        if (! $result->passed) {
            return $this->fail(
                $isAjax,
                reason: $result->reason,
                message: 'Captcha rejected ('.$result->reason.'). Please trace the moving target into the goal and try again.',
            );
        }

        $signup = WaitlistSignup::firstOrCreate(
            ['email' => $validated['email']],
            [
                'faucetpay_email' => $validated['faucetpay_email'] ?? null,
                'referral_code' => $validated['referral_code'] ?? null,
                'source' => $validated['source'] ?? $request->headers->get('referer'),
                'client_ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
            ]
        );

        // Send the confirmation email only on the first sign-up so refreshes
        // don't spam the address. Mail is queued (ShouldQueue) so the HTTP
        // response stays fast even if the SMTP server is slow.
        if ($signup->wasRecentlyCreated) {
            try {
                Mail::to($signup->email)->queue(new WaitlistConfirmation(
                    email: $signup->email,
                    faucetpayEmail: $signup->faucetpay_email,
                    referralCode: $signup->referral_code,
                ));
            } catch (\Throwable $e) {
                Log::warning('waitlist mail queue failed', ['email' => $signup->email, 'err' => $e->getMessage()]);
            }
        }

        $message = $signup->wasRecentlyCreated
            ? "We've sent a confirmation email to {$signup->email}. Check your inbox (and the spam folder) — the activation link arrives the moment SatPeek opens."
            : "{$signup->email} is already on the list. We'll email you the moment SatPeek opens.";

        return $this->ok(
            $request,
            $isAjax,
            email: $signup->email,
            message: $message,
            recentlyCreated: $signup->wasRecentlyCreated,
        );
    }

    private function ok(Request $request, bool $isAjax, string $email, string $message, bool $recentlyCreated): JsonResponse|RedirectResponse
    {
        if ($isAjax) {
            return response()->json([
                'status' => 'ok',
                'email' => $email,
                'message' => $message,
                'recently_created' => $recentlyCreated,
            ]);
        }

        return redirect()->route('register')->with('status', $message);
    }

    private function fail(bool $isAjax, string $reason, string $message): JsonResponse|RedirectResponse
    {
        if ($isAjax) {
            return response()->json([
                'status' => 'error',
                'reason' => $reason,
                'message' => $message,
            ], 422);
        }

        return redirect()
            ->route('register')
            ->withErrors(['captcha' => $message])
            ->withInput();
    }
}
