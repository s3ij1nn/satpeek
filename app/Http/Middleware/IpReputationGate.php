<?php

namespace App\Http\Middleware;

use App\IpReputation\Contracts\IpReputationProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard gate for high-risk IPs at the perimeter — used on registration and
 * withdrawal endpoints where the cost of admitting a proxy/VPN is highest.
 *
 * Soft signals (PTC heartbeat, captcha solve) keep using the IpReputationSignal
 * so a borderline IP can still earn a low score but not be hard-blocked from
 * solving captchas mid-session.
 */
class IpReputationGate
{
    public function __construct(private readonly IpReputationProvider $provider) {}

    public function handle(Request $request, Closure $next, ?string $minRisk = null): Response
    {
        $ip = $request->ip();
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return $next($request);
        }

        $verdict = $this->provider->lookup($ip);
        if ($verdict === null) {
            return $next($request);
        }

        $threshold = (int) ($minRisk ?? 70);

        if ($verdict->isTor || $verdict->isProxy || $verdict->isVpn || ($verdict->risk !== null && $verdict->risk >= $threshold)) {
            return response()->json([
                'error' => 'ip_blocked',
                'reason' => $verdict->isTor ? 'tor_exit_node'
                    : ($verdict->isVpn ? 'vpn_detected'
                    : ($verdict->isProxy ? 'proxy_detected'
                    : 'high_risk_ip')),
            ], 403);
        }

        return $next($request);
    }
}
