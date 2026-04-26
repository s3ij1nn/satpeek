<?php

namespace App\IpReputation\Contracts;

/**
 * Normalised verdict from any IP reputation provider.
 *
 * `risk` is a 0-100 score where higher = more suspicious. `null` means the
 * provider does not expose a numeric risk and the boolean fields should be
 * used instead.
 */
final class IpVerdict
{
    public function __construct(
        public readonly string $ip,
        public readonly bool $isProxy,
        public readonly bool $isVpn,
        public readonly bool $isDatacenter,
        public readonly bool $isTor,
        public readonly ?int $asn,
        public readonly ?string $countryCode,
        public readonly ?int $risk,
        public readonly string $source,
        public readonly array $raw = [],
    ) {}

    public static function clean(string $ip, string $source): self
    {
        return new self($ip, false, false, false, false, null, null, 0, $source);
    }

    public function isBlocked(): bool
    {
        return $this->isProxy || $this->isVpn || $this->isDatacenter || $this->isTor;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ip' => $this->ip,
            'is_proxy' => $this->isProxy,
            'is_vpn' => $this->isVpn,
            'is_datacenter' => $this->isDatacenter,
            'is_tor' => $this->isTor,
            'asn' => $this->asn,
            'country' => $this->countryCode,
            'risk' => $this->risk,
            'source' => $this->source,
        ];
    }
}
