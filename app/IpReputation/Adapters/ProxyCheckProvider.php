<?php

namespace App\IpReputation\Adapters;

use App\IpReputation\Contracts\IpReputationProvider;
use App\IpReputation\Contracts\IpVerdict;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * ProxyCheck.io provider — https://proxycheck.io/
 *
 * Endpoint: GET https://proxycheck.io/v2/{ip}?key={api_key}&vpn=1&asn=1&risk=1
 *
 * Response shape:
 *   {
 *     status: "ok",
 *     "1.2.3.4": {
 *        proxy: "yes" | "no",
 *        type: "VPN" | "Compromised Server" | "Web Proxy" | "Residential" | ...,
 *        asn: "AS1234",
 *        country: "United States",
 *        isocode: "US",
 *        risk: 78,
 *        operator: "Mullvad VPN AB",
 *     }
 *   }
 *
 * Free tier is 1000 queries/day. Wrap in CachedIpReputationProvider.
 */
class ProxyCheckProvider implements IpReputationProvider
{
    public function __construct(
        private readonly Client $http,
        private readonly string $apiKey,
        private readonly string $apiBase = 'https://proxycheck.io/v2',
        private readonly int $timeoutSec = 4,
    ) {}

    public function name(): string
    {
        return 'proxycheck';
    }

    public function lookup(string $ip): ?IpVerdict
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $query = http_build_query(array_filter([
            'key' => $this->apiKey ?: null,
            'vpn' => '1',
            'asn' => '1',
            'risk' => '1',
        ]));

        try {
            $response = $this->http->request('GET', rtrim($this->apiBase, '/').'/'.$ip.'?'.$query, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => $this->timeoutSec,
                'connect_timeout' => 2,
            ]);
        } catch (GuzzleException $e) {
            Log::debug('proxycheck lookup failed', ['ip' => $ip, 'err' => $e->getMessage()]);

            return null;
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        if (! is_array($data)) {
            return null;
        }
        if (($data['status'] ?? '') === 'error' || ($data['status'] ?? '') === 'denied') {
            Log::debug('proxycheck error', ['ip' => $ip, 'status' => $data['status'], 'message' => $data['message'] ?? null]);

            return null;
        }

        $entry = $data[$ip] ?? null;
        if (! is_array($entry)) {
            return null;
        }

        $proxy = strtolower((string) ($entry['proxy'] ?? 'no')) === 'yes';
        $type = strtolower((string) ($entry['type'] ?? ''));
        $isVpn = $proxy && str_contains($type, 'vpn');
        $isTor = str_contains($type, 'tor');
        $isDatacenter = $proxy && (str_contains($type, 'compromised') || str_contains($type, 'hosting') || str_contains($type, 'public') || str_contains($type, 'datacenter'));

        $asn = null;
        if (isset($entry['asn']) && is_string($entry['asn'])) {
            $asn = (int) preg_replace('/[^0-9]/', '', $entry['asn']);
            if ($asn === 0) {
                $asn = null;
            }
        }

        return new IpVerdict(
            ip: $ip,
            isProxy: $proxy,
            isVpn: $isVpn,
            isDatacenter: $isDatacenter,
            isTor: $isTor,
            asn: $asn,
            countryCode: isset($entry['isocode']) ? (string) $entry['isocode'] : null,
            risk: isset($entry['risk']) ? (int) $entry['risk'] : ($proxy ? 70 : 0),
            source: $this->name(),
            raw: $entry,
        );
    }
}
