<?php

namespace App\Captcha\Contracts;

final class VerificationResult
{
    public function __construct(
        public readonly bool $passed,
        public readonly string $reason,
        public readonly float $confidence,
        public readonly array $signals = []
    ) {}

    public static function pass(float $confidence = 1.0, array $signals = []): self
    {
        return new self(true, 'ok', $confidence, $signals);
    }

    public static function fail(string $reason, array $signals = []): self
    {
        return new self(false, $reason, 0.0, $signals);
    }
}
