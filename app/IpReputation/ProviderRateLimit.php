<?php

declare(strict_types=1);

namespace App\IpReputation;

use Illuminate\Support\Facades\Cache;

/**
 * Per-provider rate-limit cooldown. When a provider receives a "quota
 * exceeded" signal from its upstream, it calls {@see markLimited()} to set
 * a cache key that future calls (and `isLimited()` checks at the top of
 * the provider's `lookup()`) read.
 *
 * The CompositeProvider's "first non-null wins" semantics already give us
 * automatic fallback when the primary returns null — combining that with
 * skipping the API call entirely while limited means IPHub takes over for
 * ProxyCheck without burning the IPHub quota on lookups ProxyCheck would
 * have served if it had room.
 *
 * Cache TTL defaults to 1 h. ProxyCheck and IPHub free-tier quotas reset
 * daily, but provider-specific quirks (e.g. burst-rate limits within the
 * day) are handled by re-detecting on the next non-cached call.
 */
class ProviderRateLimit
{
    private const KEY_PREFIX = 'ip_reputation:rate_limit:';

    public static function markLimited(string $providerName, int $cooldownSeconds = 3600): void
    {
        Cache::put(self::KEY_PREFIX.$providerName, true, $cooldownSeconds);
    }

    public static function isLimited(string $providerName): bool
    {
        return (bool) Cache::get(self::KEY_PREFIX.$providerName, false);
    }

    public static function clear(string $providerName): void
    {
        Cache::forget(self::KEY_PREFIX.$providerName);
    }
}
