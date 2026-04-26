<?php

namespace App\Offerwall\Contracts;

final class OfferDescriptor
{
    public function __construct(
        public readonly string $source,
        public readonly string $externalId,
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $targetUrl,
        public readonly int $rewardSat,
        public readonly int $durationSec,
        public readonly int $dailyLimitPerUser = 1,
        public readonly array $meta = [],
    ) {}
}
