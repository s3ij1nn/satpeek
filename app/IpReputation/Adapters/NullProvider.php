<?php

namespace App\IpReputation\Adapters;

use App\IpReputation\Contracts\IpReputationProvider;
use App\IpReputation\Contracts\IpVerdict;

/**
 * No-op provider — returns null for every lookup so the IpReputationGate
 * and IpReputationSignal short-circuit. Used in local / testing environments
 * where querying IPHub or ProxyCheck is undesirable.
 */
class NullProvider implements IpReputationProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function lookup(string $ip): ?IpVerdict
    {
        return null;
    }
}
