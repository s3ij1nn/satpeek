<?php

namespace App\IpReputation\Adapters;

use App\IpReputation\Contracts\IpReputationProvider;
use App\IpReputation\Contracts\IpVerdict;
use Closure;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Local MaxMind GeoLite2-ASN lookup.
 *
 * Lets the bot-detection stack derive an ASN per IP without an HTTP round-
 * trip — useful when IPHub / ProxyCheck are unconfigured (local dev) or
 * rate-limited (production peak). The .mmdb file is operator-supplied
 * (MaxMind license forbids redistribution); when the file is absent the
 * provider degrades to "no signal" so the rest of the IpReputation
 * composite keeps working.
 *
 * Notes:
 *   - We don't ship the database — see .env.example for the download path.
 *   - GeoLite2-ASN does not classify hosting / VPN / proxy. The ASN we
 *     return is fed to AsnStaticListSignal which compares against the
 *     operator-curated DATACENTER_ASNS list.
 *   - Private / loopback IPs are short-circuited locally; MaxMind would
 *     just throw AddressNotFoundException for them.
 */
class MaxMindAsnProvider implements IpReputationProvider
{
    private ?Reader $reader = null;
    private bool $readerLoadFailed = false;

    /**
     * @param  Closure|null  $readerFactory  () => GeoIp2\Database\Reader
     *         Optional injection point for tests; production wiring leaves
     *         this null and we lazy-load the reader from $dbPath.
     */
    public function __construct(
        private readonly string $dbPath,
        private readonly ?Closure $readerFactory = null,
    ) {}

    public function name(): string
    {
        return 'maxmind';
    }

    public function lookup(string $ip): ?IpVerdict
    {
        if ($ip === '' || self::isPrivateOrLoopback($ip)) {
            return null;
        }

        $reader = $this->reader();
        if ($reader === null) {
            return null;
        }

        try {
            $record = $reader->asn($ip);
        } catch (AddressNotFoundException) {
            // IP not in the GeoLite2-ASN database (rare for public IPv4).
            return null;
        } catch (Throwable $e) {
            // Database file corrupt / unreadable — log once but degrade
            // gracefully so the rest of the composite still works.
            Log::warning('maxmind asn lookup failed', ['ip' => $ip, 'err' => $e->getMessage()]);
            return null;
        }

        $asn = $record->autonomousSystemNumber;
        if ($asn === null || $asn <= 0) {
            return null;
        }

        // GeoLite2-ASN doesn't expose proxy / VPN / datacenter classifications.
        // We surface only the ASN — downstream signals (AsnStaticListSignal)
        // decide whether the AS number is one we treat as datacenter / abuse.
        return new IpVerdict(
            ip: $ip,
            isProxy: false,
            isVpn: false,
            isDatacenter: false,
            isTor: false,
            asn: (int) $asn,
            countryCode: null,
            risk: null,
            source: $this->name(),
            raw: [
                'asn_org' => $record->autonomousSystemOrganization,
            ],
        );
    }

    private function reader(): ?Reader
    {
        if ($this->reader !== null) {
            return $this->reader;
        }
        if ($this->readerLoadFailed) {
            return null;
        }

        try {
            if ($this->readerFactory !== null) {
                $this->reader = ($this->readerFactory)();
                return $this->reader;
            }
            if ($this->dbPath === '' || ! is_file($this->dbPath)) {
                $this->readerLoadFailed = true;
                return null;
            }
            $this->reader = new Reader($this->dbPath);
            return $this->reader;
        } catch (Throwable $e) {
            // Bad file format (truncated download, wrong .mmdb type, …).
            // Mark as permanently failed so we don't re-try every lookup.
            $this->readerLoadFailed = true;
            Log::warning('maxmind asn reader init failed', [
                'path' => $this->dbPath,
                'err' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Skip RFC1918 / loopback / link-local / CGNAT space — these never have
     * a public ASN and would just spam the lookup with AddressNotFoundException.
     */
    private static function isPrivateOrLoopback(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true; // garbage IPs short-circuit too
        }
        return ! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
    }
}
