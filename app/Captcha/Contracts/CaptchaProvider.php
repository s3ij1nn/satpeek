<?php

namespace App\Captcha\Contracts;

interface CaptchaProvider
{
    public function name(): string;

    /**
     * Build a fresh challenge. Pure function of (seed, viewport).
     *
     * @return array{
     *     challengeId: string,
     *     seed: string,
     *     payload: array<string, mixed>,
     *     expectedShape: array<int, array{x: float, y: float, t: float}>,
     *     ttlMs: int
     * }
     */
    public function issue(string $sessionId, ?int $userId, array $viewport): array;

    /**
     * Verify a submitted trajectory.
     *
     * @param  array<string, mixed>  $challenge  Stored challenge record (with expectedShape).
     * @param  array<int, array<string, mixed>>  $points  Submitted points.
     * @param  array<string, mixed>  $context  IP / fingerprint / timing.
     * @return VerificationResult
     */
    public function verify(array $challenge, array $points, array $context): VerificationResult;
}
