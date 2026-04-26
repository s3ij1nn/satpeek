<?php

namespace App\Offerwall\Contracts;

final class ViewSession
{
    public function __construct(
        public readonly string $epochToken,
        public readonly string $redirectUrl,
        public readonly int $durationSec,
        public readonly array $meta = [],
    ) {}
}
