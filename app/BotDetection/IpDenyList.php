<?php

declare(strict_types=1);

namespace App\BotDetection;

use App\Http\Middleware\IpBlocked;
use App\Models\IpBlockEntry;
use Illuminate\Support\Facades\Cache;

/**
 * Operator-managed IP deny list. Counterpart to {@see IpAllowlist}.
 *
 * The matching logic is delegated to IpAllowlist::matches() so the CIDR
 * algorithm stays in one place — same parser, same IPv4/IPv6 parity,
 * same garbage-tolerance contract. The two lists differ only in their
 * source (env var vs DB) and their callers (SharedIpSignal suppression
 * vs the {@see IpBlocked} hard gate).
 *
 * The DB read is cached for 30 s. /admin's CRUD path explicitly busts
 * the cache (see {@see flush()}) so an operator's "block this IP NOW"
 * action takes effect on the next request, not the next 30 s tick. The
 * cache is the only thing standing between the global middleware and
 * a per-request SELECT * FROM ip_block_entries — a busy edge with a
 * mostly-empty deny list still pays for the round-trip otherwise.
 */
class IpDenyList
{
    private const CACHE_KEY = 'ip-deny-list:cidrs:v1';

    private const CACHE_TTL_SECONDS = 30;

    /**
     * Returns true if `$ip` matches any active deny-list entry.
     *
     * Defensive on bad input: a malformed `$ip` returns false (caller
     * treats the request as not-blocked, which is the right default —
     * a parser bug shouldn't auto-403 every legitimate visitor).
     */
    public static function blocks(string $ip): bool
    {
        $list = self::cidrs();
        if ($list === []) {
            return false;
        }

        return IpAllowlist::matches($ip, $list);
    }

    /**
     * Clear the cached CIDR list. Called by IpBlockEntry CRUD paths so
     * an operator's add/delete is reflected on the very next request.
     * The 30 s TTL is the floor for staleness if this is ever missed.
     */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Cached pluck of the active deny-list CIDRs.
     *
     * @return array<int, string>
     */
    private static function cidrs(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            return IpBlockEntry::query()->pluck('cidr')->all();
        });
    }
}
