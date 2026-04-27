<?php

namespace App\Captcha;

use App\BotDetection\PolicyEnforcer;
use App\Captcha\Contracts\CaptchaProvider;
use App\Models\CaptchaChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ChallengeBuilder
{
    public function __construct(
        private readonly CaptchaProvider $provider,
        private readonly PolicyEnforcer $policy,
    ) {}

    /**
     * @return array<string, mixed> Public payload safe to send to the client (no expectedShape).
     */
    public function issue(Request $request): array
    {
        $sessionId = (string) $request->header('X-SP-Session', $request->cookie('satpeek_sid', ''));
        if ($sessionId === '') {
            $sessionId = bin2hex(random_bytes(16));
        }
        $user = $request->user();
        $userId = $user?->id;
        $viewport = [
            'w' => (int) $request->input('vw', 320),
            'h' => (int) $request->input('vh', 240),
        ];
        $fingerprint = (string) $request->header('X-SP-Fingerprint', '');
        $ja4 = (string) $request->header('X-SP-JA4', '');

        // Look up tier-driven captcha difficulty for authenticated users —
        // a suspect / likely_bot user gets a harder curve than a trust user.
        // Anonymous (login / register form) defaults to 1 since there's no
        // user to score yet.
        $difficulty = $user instanceof User ? $this->policy->captchaDifficulty($user) : 1;

        $issued = $this->provider->issue($sessionId, $userId, $viewport, $difficulty);

        CaptchaChallenge::create([
            'challenge_id' => $issued['challengeId'],
            'user_id' => $userId,
            'session_id' => $sessionId,
            'provider' => $this->provider->name(),
            'seed' => $issued['seed'],
            'expected_shape' => $issued['expectedShape'],
            'fingerprint_hash' => $fingerprint !== '' ? hash('sha256', $fingerprint) : null,
            'client_ip' => $request->ip(),
            'ja4' => $ja4 !== '' ? $ja4 : null,
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
            'status' => 'issued',
            'issued_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMilliseconds($issued['ttlMs']),
        ]);

        return [
            'challengeId' => $issued['challengeId'],
            'provider' => $this->provider->name(),
            'payload' => $issued['payload'],
            'expiresAt' => Carbon::now()->addMilliseconds($issued['ttlMs'])->toIso8601String(),
            'ttlMs' => $issued['ttlMs'],
        ];
    }
}
