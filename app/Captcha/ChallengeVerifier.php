<?php

namespace App\Captcha;

use App\BotDetection\ScoreEngine;
use App\Captcha\Contracts\CaptchaProvider;
use App\Captcha\Contracts\VerificationResult;
use App\Models\CaptchaChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        // Re-score the user after a successful captcha verify so signals
        // that read captcha behaviour (response_time, trajectory_entropy,
        // failure_rate, fingerprint_consistency, heartbeat_gap) feed the
        // tier transition without waiting for the next login. Throttle
        // suppresses repeated re-evals on a chatty user. Defensive
        // try/catch — a scoring failure must NEVER reject a captcha that
        // the verifier already accepted.
        if ($result->passed && $challenge->user_id !== null) {
            self::reEvaluateUser((int) $challenge->user_id);
        }

        return $result;
    }

    private static function reEvaluateUser(int $userId): void
    {
        try {
            $user = User::find($userId);
            if ($user) {
                app(ScoreEngine::class)->evaluateThrottled($user);
            }
        } catch (Throwable $e) {
            Log::warning('bot score re-eval failed at captcha verify', [
                'user_id' => $userId,
                'err' => $e->getMessage(),
            ]);
        }
    }
}
