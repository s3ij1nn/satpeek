<?php

namespace App\BotDetection\Signals;

use App\IpReputation\Contracts\IpReputationProvider;
use App\Models\CaptchaChallenge;
use App\Models\User;

/**
 * Defence-in-depth ASN check against an operator-maintained list.
 *
 * The live `IpReputationSignal` already flags datacenter IPs via IPHub /
 * ProxyCheck. This signal adds a second filter that lets the operator pin
 * specific AS numbers (datacenters, abusive ranges, scrapers' VPS provider)
 * via the DATACENTER_ASNS env var without waiting for the upstream
 * reputation feed to catch up.
 *
 * The lookup goes through IpReputationProvider so we share the verdict
 * cache the live signal already populates — no extra HTTP calls, no extra
 * API quota. When neither provider is configured and no verdict is
 * returned, the signal silently scores 0.0 (no signal).
 */
class AsnStaticListSignal implements Signal
{
    public function __construct(private readonly IpReputationProvider $provider) {}

    public function name(): string
    {
        return 'asn_static_list';
    }

    public function evaluate(User $user): array
    {
        $list = $this->configuredAsns();
        if ($list === []) {
            return ['score' => 0.0, 'detail' => ['reason' => 'no_static_list']];
        }

        $ips = CaptchaChallenge::where('user_id', $user->id)
            ->whereNotNull('client_ip')
            ->orderByDesc('id')
            ->limit(20)
            ->pluck('client_ip')
            ->unique()
            ->values();

        if ($ips->isEmpty()) {
            return ['score' => 0.0, 'detail' => ['samples' => 0]];
        }

        $sampled = 0;
        $hits = 0;
        $matches = [];

        foreach ($ips as $ip) {
            $verdict = $this->provider->lookup((string) $ip);
            if ($verdict === null || $verdict->asn === null) {
                continue;
            }
            $sampled++;
            if (in_array($verdict->asn, $list, true)) {
                $hits++;
                $matches[] = ['ip' => $ip, 'asn' => $verdict->asn];
            }
        }

        if ($sampled === 0) {
            return ['score' => 0.0, 'detail' => ['samples' => $ips->count(), 'reason' => 'no_provider_response']];
        }

        $hitRate = $hits / $sampled;

        return [
            'score' => round(min(1.0, $hitRate), 3),
            'detail' => [
                'sampled' => $sampled,
                'hits' => $hits,
                'list_size' => count($list),
                'matches' => $matches,
            ],
        ];
    }

    /**
     * @return array<int, int>
     */
    private function configuredAsns(): array
    {
        $raw = (array) config('satpeek.datacenter_asns', []);
        $out = [];
        foreach ($raw as $entry) {
            $n = (int) preg_replace('/[^0-9]/', '', (string) $entry);
            if ($n > 0) {
                $out[] = $n;
            }
        }
        return array_values(array_unique($out));
    }
}
