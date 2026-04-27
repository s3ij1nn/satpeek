<?php

declare(strict_types=1);

namespace App\BotDetection;

/**
 * CIDR-aware match for an operator-supplied list of "this IP is a known
 * shared NAT, don't flag cross-account use of it" prefixes. Used by
 * {@see Signals\SharedIpSignal} to suppress sock-puppet false positives
 * for campus wifi, mobile carrier ranges, household routers, corporate
 * proxies, etc.
 *
 * The list lives at `bot_score.shared_ip.allowlist` in config (env var
 * `BOTSCORE_SHARED_IP_ALLOWLIST`, comma-separated). Entries are matched
 * exactly (single IP) or as CIDR prefix (`1.2.3.0/24`, `2001:db8::/32`).
 * Garbage entries are silently skipped — never throw at config-time.
 *
 * IPv4 and IPv6 are both supported. Prefix length must be 0-32 for IPv4
 * and 0-128 for IPv6; out-of-range entries are skipped.
 */
class IpAllowlist
{
    /**
     * Returns true if `$ip` matches any entry in `$allowlist`. Returns
     * false on invalid `$ip` (caller will then treat the IP as not-
     * allowlisted, which is the safe-by-default behaviour — a malformed
     * IP shouldn't accidentally bypass scoring).
     *
     * @param  array<int, string>  $allowlist
     */
    public static function matches(string $ip, array $allowlist): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach ($allowlist as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (self::ipMatchesEntry($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function ipMatchesEntry(string $ip, string $entry): bool
    {
        // Single-IP entry — straight equality on the canonical form.
        if (! str_contains($entry, '/')) {
            return inet_pton($ip) === inet_pton($entry);
        }

        [$prefix, $bits] = explode('/', $entry, 2);
        if (! filter_var($prefix, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (! ctype_digit($bits)) {
            return false;
        }
        $bits = (int) $bits;

        $ipBin = inet_pton($ip);
        $prefixBin = inet_pton($prefix);
        if ($ipBin === false || $prefixBin === false) {
            return false;
        }
        // Prefix family must match the candidate IP's family.
        if (strlen($ipBin) !== strlen($prefixBin)) {
            return false;
        }
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        if ($bits === 0) {
            return true; // /0 matches everything in the same family
        }

        $fullBytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($prefixBin, 0, $fullBytes)) {
            return false;
        }
        if ($remainderBits === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $remainderBits)) & 0xFF);

        return (substr($ipBin, $fullBytes, 1) & $mask) === (substr($prefixBin, $fullBytes, 1) & $mask);
    }
}
