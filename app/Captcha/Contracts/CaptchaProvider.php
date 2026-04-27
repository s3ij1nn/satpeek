<?php

namespace App\Captcha\Contracts;

use App\BotDetection\PolicyEnforcer;

interface CaptchaProvider
{
    public function name(): string;

    /**
     * Build a fresh challenge. Pure function of (seed, viewport, difficulty).
     *
     * `$difficulty` is sourced by ChallengeBuilder from
     * {@see PolicyEnforcer::captchaDifficulty()} —
     * 1 = trust (default), 2 = suspect, 3 = likely_bot. Anonymous
     * pre-auth challenges (login / register form) stay at 1 because
     * there's no user to score yet. Providers may interpret the level
     * however suits their challenge model; the trajectory provider
     * scales amplitude / frequency / shape tolerance.
     *
     * @return array{
     *     challengeId: string,
     *     seed: string,
     *     payload: array<string, mixed>,
     *     expectedShape: array<int, array{x: float, y: float, t: float}>,
     *     ttlMs: int
     * }
     */
    public function issue(string $sessionId, ?int $userId, array $viewport, int $difficulty = 1): array;

    /**
     * Verify a submitted trajectory.
     *
     * @param  array<string, mixed>  $challenge  Stored challenge record (with expectedShape).
     * @param  array<int, array<string, mixed>>  $points  Submitted points.
     * @param  array<string, mixed>  $context  IP / fingerprint / timing.
     */
    public function verify(array $challenge, array $points, array $context): VerificationResult;
}
