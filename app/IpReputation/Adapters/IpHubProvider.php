<?php

namespace App\IpReputation\Adapters;

use App\IpReputation\Contracts\IpReputationProvider;
use App\IpReputation\Contracts\IpVerdict;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * IPHub provider — https://iphub.info/api
 *
 * Endpoint: GET https://v2.api.iphub.info/ip/{ip}
 * Auth:     X-Key: <api_key>
 *
 * Response (relevant fields):
 *   block:        0 = residential / unblocked
 *                 1 = non-residential (datacenter / VPN / proxy)
 *                 2 = mixed (often residential proxy / suspicious)
 *   countryCode:  ISO 3166-1 alpha-2
 *   asn:          numeric AS number
 *   isp:          textual ISP / hosting provider name
 *
 * Free tier is 1000 lookups/day — *always* wrap in CachedIpReputationProvider.
 */
class IpHubProvider implements IpReputationProvider
{
    public function __construct(
        private readonly Client $http,
        private readonly string $apiKey,
        private readonly string $apiBase = 'https://v2.api.iphub.info',
        private readonly int $timeoutSec = 4,
    ) {}

    public function name(): string
    {
        return 'iphub';
    }

    public function lookup(string $ip): ?IpVerdict
    {
        if ($this->apiKey === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        try {
            $response = $this->http->request('GET', rtrim($this->apiBase, '/').'/ip/'.$ip, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Key' => $this->apiKey,
                ],
                'timeout' => $this->timeoutSec,
                'connect_timeout' => 2,
            ]);
        } catch (GuzzleException $e) {
            Log::debug('iphub lookup failed', ['ip' => $ip, 'err' => $e->getMessage()]);

            return null;
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        if (! is_array($data)) {
            return null;
        }

        $block = (int) ($data['block'] ?? 0);
        $isDatacenter = $block === 1;
        $isMixed = $block === 2;

        $isp = strtolower((string) ($data['isp'] ?? ''));
        $vpnHints = ['vpn', 'tunnel', 'proxy', 'mullvad', 'nordvpn', 'expressvpn', 'surfshark'];
        $isVpn = $isDatacenter && self::matchesAny($isp, $vpnHints);
        $isProxy = $isMixed || self::matchesAny($isp, ['proxy']);
        $isTor = self::matchesAny($isp, ['tor', 'tor exit', 'tor relay']);

        return new IpVerdict(
            ip: $ip,
            isProxy: $isProxy,
            isVpn: $isVpn,
            isDatacenter: $isDatacenter,
            isTor: $isTor,
            asn: isset($data['asn']) ? (int) $data['asn'] : null,
            countryCode: isset($data['countryCode']) ? (string) $data['countryCode'] : null,
            risk: $isDatacenter ? 90 : ($isMixed ? 60 : 0),
            source: $this->name(),
            raw: $data,
        );
    }

    /** @param  array<int, string>  $needles */
    private static function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($haystack, $n)) {
                return true;
            }
        }

        return false;
    }
}
