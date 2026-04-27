<?php

namespace App\IpReputation\Adapters;

use App\IpReputation\Contracts\IpReputationProvider;
use App\IpReputation\Contracts\IpVerdict;

/**
 * Queries multiple providers and merges their verdicts. The first non-null
 * response wins for ASN/country/risk fields; the OR of any boolean flag
 * triggers the corresponding "blocked" condition (more conservative).
 */
class CompositeProvider implements IpReputationProvider
{
    /** @param  array<int, IpReputationProvider>  $providers */
    public function __construct(private readonly array $providers) {}

    public function name(): string
    {
        return 'composite';
    }

    public function lookup(string $ip): ?IpVerdict
    {
        $verdicts = [];
        foreach ($this->providers as $p) {
            $v = $p->lookup($ip);
            if ($v) {
                $verdicts[] = $v;
            }
        }
        if (empty($verdicts)) {
            return null;
        }
        if (count($verdicts) === 1) {
            return $verdicts[0];
        }

        $isProxy = false;
        $isVpn = false;
        $isDc = false;
        $isTor = false;
        $asn = null;
        $country = null;
        $risk = 0;
        $raw = [];
        foreach ($verdicts as $v) {
            $isProxy = $isProxy || $v->isProxy;
            $isVpn = $isVpn || $v->isVpn;
            $isDc = $isDc || $v->isDatacenter;
            $isTor = $isTor || $v->isTor;
            $asn ??= $v->asn;
            $country ??= $v->countryCode;
            if ($v->risk !== null && $v->risk > $risk) {
                $risk = $v->risk;
            }
            $raw[$v->source] = $v->raw;
        }

        return new IpVerdict(
            ip: $ip,
            isProxy: $isProxy,
            isVpn: $isVpn,
            isDatacenter: $isDc,
            isTor: $isTor,
            asn: $asn,
            countryCode: $country,
            risk: $risk,
            source: 'composite',
            raw: $raw,
        );
    }
}
