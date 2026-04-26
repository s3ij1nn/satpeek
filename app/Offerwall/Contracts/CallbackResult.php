<?php

namespace App\Offerwall\Contracts;

final class CallbackResult
{
    public function __construct(
        public readonly string $source,
        public readonly string $externalId,
        public readonly ?int $userId,
        public readonly int $rewardSat,
        public readonly string $status,
        public readonly array $meta = [],
    ) {}
}
