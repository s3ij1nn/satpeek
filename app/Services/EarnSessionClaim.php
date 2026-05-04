<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Result of {@see EarnSessionClaimService::claim()}.
 *
 * The earn-session claim flow has exactly two outcomes that matter to the
 * caller: the row was credited (reward_sat goes back in the JSON payload)
 * or it wasn't (an error code goes back as `error` with a status code). We
 * return both shapes through this lightweight value object so the
 * controllers can `return $result->toJson()` without re-implementing the
 * JsonResponse structure each time.
 */
final class EarnSessionClaim
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $errorCode,
        public readonly int $httpStatus,
        public readonly int $rewardSat,
    ) {}

    public static function credited(int $rewardSat): self
    {
        return new self(true, null, 200, $rewardSat);
    }

    public static function rejected(string $errorCode, int $httpStatus = 422): self
    {
        return new self(false, $errorCode, $httpStatus, 0);
    }
}
