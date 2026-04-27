<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the real visitor IP when the deployment sits behind Cloudflare.
 *
 * Without this middleware, `request()->ip()` returns Cloudflare's edge IP,
 * which would silently break:
 *   - bot detection (asn_datacenter / asn_static_list / IpReputationGate)
 *   - captcha challenge issue + verify (client_ip locks the trace)
 *   - BitcoTask offer fetch (USER_IP segment in the publisher URL path)
 *   - webhook IP allow-list (BitcoTask postback receiver)
 *
 * Two design points worth pinning here:
 *
 *   1. **Off by default.** `TRUST_CLOUDFLARE_PROXY=false` makes this a
 *      no-op. Local dev / non-Cloudflare deployments behave exactly as
 *      they did before. The operator opts in once at deploy time when
 *      they put the origin behind Cloudflare orange-cloud.
 *
 *   2. **CF-Connecting-IP, not X-Forwarded-For.** Cloudflare APPENDS
 *      to inbound `X-Forwarded-For`, so `XFF: 1.2.3.4, REAL_CLIENT`
 *      lets a malicious client spoof `1.2.3.4` as the leftmost entry —
 *      Symfony's chain walk would then return the spoofed value.
 *      `CF-Connecting-IP`, by contrast, is OVERWRITTEN by Cloudflare on
 *      every request, so a spoofed inbound value is clobbered before
 *      the origin sees it.
 *
 *      Spoofing is still possible if the origin is reachable
 *      DIRECTLY (not behind Cloudflare). Operators enabling this flag
 *      MUST restrict inbound traffic to Cloudflare's published IP
 *      ranges at the firewall layer — that's a deployment concern this
 *      application can't enforce.
 */
class CloudflareClientIp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::trustEnabled()) {
            return $next($request);
        }

        $cfIp = trim((string) $request->headers->get('CF-Connecting-IP', ''));
        if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP)) {
            // Overwrite REMOTE_ADDR so `request()->ip()` (which falls
            // through to Symfony's `Request::getClientIp()`) returns the
            // real visitor without us having to touch every call-site.
            $request->server->set('REMOTE_ADDR', $cfIp);
        }

        return $next($request);
    }

    private static function trustEnabled(): bool
    {
        // env() is forbidden outside config/ when running with cached config —
        // satpeek.cloudflare.trust_proxy already does the env() lookup at boot
        // and casts to bool, so this is just a typed read.
        return (bool) config('satpeek.cloudflare.trust_proxy', false);
    }
}
