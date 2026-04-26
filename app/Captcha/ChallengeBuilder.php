<?php

namespace App\Captcha;

use App\Captcha\Contracts\CaptchaProvider;
use App\Models\CaptchaChallenge;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ChallengeBuilder
{
    public function __construct(private readonly CaptchaProvider $provider) {}

    /**
     * @return array<string, mixed>  Public payload safe to send to the client (no expectedShape).
     */
    public function issue(Request $request): array
    {
        $sessionId = (string) $request->header('X-SP-Session', $request->cookie('satpeek_sid', ''));
        if ($sessionId === '') {
            $sessionId = bin2hex(random_bytes(16));
        }
        $userId = $request->user()?->id;
        $viewport = [
            'w' => (int) $request->input('vw', 320),
            'h' => (int) $request->input('vh', 240),
        ];
        $fingerprint = (string) $request->header('X-SP-Fingerprint', '');
        $ja4 = (string) $request->header('X-SP-JA4', '');

        $issued = $this->provider->issue($sessionId, $userId, $viewport);

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
