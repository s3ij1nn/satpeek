<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Earning-route gate that refuses requests when the user's last adblock
 * report is either `detected` OR stale (older than the configured TTL).
 *
 * Three outcomes:
 *
 *   - status `clean` AND fresh → pass through
 *   - status `detected`        → 403 `adblock_detected`
 *   - status null OR stale     → 403 `adblock_check_required`
 *
 * The "stale = bad" rule is the anti-bypass measure. A bot that simply
 * never POSTs `/api/adblock/report` would otherwise sit at `null` forever
 * and earn freely. Gating on freshness forces every active session to
 * keep producing a fresh detection signal — and the detection JS posts
 * on every page load, so an honest user is always within the window.
 *
 * TTL is `satpeek.adblock.check_ttl_seconds` (default 300 s = 5 min).
 * Generous enough that a slow page-load + user-action sequence stays
 * inside; tight enough that a bot can't open one tab and grind for
 * hours without re-checking.
 */
class AdblockGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            // Auth middleware should have run before this gate; if not,
            // pass through and let the auth gate emit its own response.
            return $next($request);
        }

        $ttl = (int) config('satpeek.adblock.check_ttl_seconds', 300);
        $status = (string) ($user->adblock_status ?? '');
        $checkedAt = $user->adblock_checked_at;
        $stale = $checkedAt === null || $checkedAt->lt(Carbon::now()->subSeconds($ttl));

        if ($status === 'detected') {
            return response()->json([
                'error' => 'adblock_detected',
                'message' => 'Adblock or Brave shields detected. Disable to continue earning.',
            ], 403);
        }
        if ($stale || $status !== 'clean') {
            return response()->json([
                'error' => 'adblock_check_required',
                'message' => 'Adblock check expired or never run. Reload the page to re-check.',
            ], 403);
        }

        return $next($request);
    }
}
