<?php

namespace App\IpReputation\Contracts;

interface IpReputationProvider
{
    public function name(): string;

    /**
     * Look up an IP. Returns null when the provider is not configured or
     * the lookup failed (the caller should treat null as "no signal" and
     * fall through to the next provider).
     */
    public function lookup(string $ip): ?IpVerdict;
}
