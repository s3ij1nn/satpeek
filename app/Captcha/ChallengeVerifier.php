<?php

namespace App\Captcha;

use App\Captcha\Contracts\CaptchaProvider;
use App\Captcha\Contracts\VerificationResult;
use App\Models\CaptchaChallenge;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ChallengeVerifier
{
    public function __construct(private readonly CaptchaProvider $provider) {}

    public function verify(Request $request, string $challengeId, array $points): VerificationResult
    {
        /** @var CaptchaChallenge|null $challenge */
        $challenge = CaptchaChallenge::where('challenge_id', $challengeId)->first();
        if (! $challenge) {
            return VerificationResult::fail('challenge_not_found');
        }
        if ($challenge->status !== 'issued') {
            return VerificationResult::fail('challenge_already_resolved:'.$challenge->status);
        }
        if (Carbon::now()->greaterThan($challenge->expires_at)) {
            $challenge->update(['status' => 'expired', 'rejection_reason' => 'ttl_exceeded']);

            return VerificationResult::fail('challenge_expired');
        }

        // Carbon 3 (Laravel 11) returns a signed float from diffInMilliseconds —
        // when `now` is later than `issued_at`, the result is *negative*. Use
        // raw millisecond timestamps to compute a positive elapsed value.
        // issued_at is non-nullable on the schema so no guard needed.
        $solveMs = max(0, (int) (Carbon::now()->getPreciseTimestamp(3) - $challenge->issued_at->getPreciseTimestamp(3)));

        $fingerprint = (string) $request->header('X-SP-Fingerprint', '');
        $providedFp = $fingerprint !== '' ? hash('sha256', $fingerprint) : null;

        $context = [
            'solve_ms' => $solveMs,
            'fingerprint_hash' => $providedFp,
            'client_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        $challengeArr = [
            'expected_shape' => $challenge->expected_shape,
            'fingerprint_hash' => $challenge->fingerprint_hash,
        ];

        $result = $this->provider->verify($challengeArr, $points, $context);

        $challenge->update([
            'status' => $result->passed ? 'verified' : 'rejected',
            'rejection_reason' => $result->passed ? null : $result->reason,
            'resolved_at' => Carbon::now(),
            'meta' => array_merge((array) $challenge->meta, [
                'signals' => $result->signals,
                'confidence' => $result->confidence,
            ]),
        ]);

        return $result;
    }
}
