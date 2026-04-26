<?php

namespace App\BotDetection\Signals;

use App\IpReputation\Contracts\IpReputationProvider;
use App\Models\CaptchaChallenge;
use App\Models\User;

/**
 * Real IP reputation lookup via IPHub + ProxyCheck.io (Composite + cached).
 *
 * Replaces the static datacenter ASN list with live provider queries.
 * Falls back to a 0.0 score when no provider is configured (e.g. local
 * development without API keys) — the static `AsnDatacenterSignal` can
 * still be wired up alongside as a defence-in-depth layer.
 */
class IpReputationSignal implements Signal
{
    public function __construct(private readonly IpReputationProvider $provider) {}

    public function name(): string
    {
        return 'asn_datacenter';
    }

    public function evaluate(User $user): array
    {
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

        $hits = 0;
        $totalRisk = 0;
        $sampled = 0;
        $matches = [];

        foreach ($ips as $ip) {
            $verdict = $this->provider->lookup((string) $ip);
            if ($verdict === null) {
                continue;
            }
            $sampled++;
            if ($verdict->isBlocked()) {
                $hits++;
                $matches[] = [
                    'ip' => $ip,
                    'proxy' => $verdict->isProxy,
                    'vpn' => $verdict->isVpn,
                    'datacenter' => $verdict->isDatacenter,
                    'tor' => $verdict->isTor,
                    'asn' => $verdict->asn,
                    'country' => $verdict->countryCode,
                    'risk' => $verdict->risk,
                ];
            }
            if ($verdict->risk !== null) {
                $totalRisk += $verdict->risk;
            }
        }

        if ($sampled === 0) {
            // Provider not configured / unreachable. Don't penalise.
            return ['score' => 0.0, 'detail' => ['samples' => $ips->count(), 'reason' => 'no_provider_response']];
        }

        $hitRate = $hits / $sampled;
        $avgRisk = $totalRisk / max(1, $sampled);

        // Combine: hit rate is the binary signal, avg risk smooths it.
        $score = min(1.0, max(
            $hitRate * 1.1,
            $avgRisk / 100.0
        ));

        return [
            'score' => round($score, 3),
            'detail' => [
                'sampled' => $sampled,
                'hits' => $hits,
                'avg_risk' => round($avgRisk, 1),
                'matches' => $matches,
            ],
        ];
    }
}
