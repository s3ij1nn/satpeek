<?php

namespace App\IpReputation\Adapters;

use App\IpReputation\Contracts\IpReputationProvider;
use App\IpReputation\Contracts\IpVerdict;
use Illuminate\Support\Facades\Cache;

/**
 * Caches verdicts in the configured cache store (Redis in production).
 * Reputation data does not change quickly, so a 24h default TTL is plenty
 * and keeps us inside free-tier quotas. Negative results (provider returned
 * null) are cached for a shorter duration so transient outages clear fast.
 */
class CachedProvider implements IpReputationProvider
{
    public function __construct(
        private readonly IpReputationProvider $inner,
        private readonly int $ttlSeconds = 86400,
        private readonly int $negativeTtlSeconds = 600,
    ) {}

    public function name(): string
    {
        return 'cached:'.$this->inner->name();
    }

    public function lookup(string $ip): ?IpVerdict
    {
        // Skip private / loopback / link-local addresses — they are never
        // reachable from the public internet so reputation lookups would
        // either fail or burn quota. Returning null lets the gate / signal
        // treat the request as "no signal" and fall through.
        if (self::isLocalOrPrivate($ip)) {
            return null;
        }

        $key = 'ip_reputation:'.$this->inner->name().':'.$ip;
        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('verdict', $cached)) {
            $v = $cached['verdict'];

            return $v === null ? null : new IpVerdict(
                ip: $v['ip'],
                isProxy: (bool) $v['is_proxy'],
                isVpn: (bool) $v['is_vpn'],
                isDatacenter: (bool) $v['is_datacenter'],
                isTor: (bool) $v['is_tor'],
                asn: $v['asn'] !== null ? (int) $v['asn'] : null,
                countryCode: $v['country'],
                risk: $v['risk'] !== null ? (int) $v['risk'] : null,
                source: $v['source'],
                raw: $v['raw'] ?? [],
            );
        }

        $verdict = $this->inner->lookup($ip);
        $payload = $verdict === null
            ? ['verdict' => null]
            : ['verdict' => $verdict->toArray() + ['raw' => $verdict->raw]];
        Cache::put($key, $payload, $verdict === null ? $this->negativeTtlSeconds : $this->ttlSeconds);

        return $verdict;
    }

    /**
     * Detect IPs that should never be sent to a remote reputation API:
     *   - Loopback (127.0.0.0/8, ::1)
     *   - Link-local (169.254.0.0/16, fe80::/10)
     *   - RFC1918 private (10/8, 172.16/12, 192.168/16)
     *   - IPv6 unique-local (fc00::/7)
     *   - Reserved (0.0.0.0, broadcast)
     *
     * `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` rejects exactly
     * these classes when used with `filter_var` validation.
     */
    public static function isLocalOrPrivate(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true; // not a valid IP — don't query.
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
