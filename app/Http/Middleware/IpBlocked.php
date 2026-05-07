<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\BotDetection\IpDenyList;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard gate that 403s any request whose source IP matches an operator
 * deny-list entry. Counterpart to the soft, score-driven blocking that
 * happens through ScoreEngine + tier policy — this is the immediate
 * "you, specifically, get nothing" override the on-call operator
 * reaches for during an active attack.
 *
 * Runs as a global middleware so the block applies to /login, /register,
 * /admin, the public landing page, every API endpoint — anywhere a
 * blocked IP could otherwise consume server resources. Placed BEFORE
 * the bot-detection signal middleware to avoid wasted ScoreEngine work
 * on already-banned addresses.
 *
 * The 403 response shape mirrors {@see IpReputationGate} so monitoring
 * tooling treats `ip_blocked` consistently regardless of whether the
 * source was reputation lookup or operator action. The reason field
 * differentiates the two so an operator triaging "why was X blocked"
 * can tell apart a DB entry vs a stale IPHub verdict.
 */
class IpBlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();
        if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return $next($request);
        }
        if (! IpDenyList::blocks($ip)) {
            return $next($request);
        }

        // JSON for AJAX / API clients; plain text page for browser
        // navigations. The page response is intentionally bare —
        // revealing "your IP is on our deny list" already tells the
        // attacker too much, but a generic 403 for a browser is
        // better UX than a JSON blob the user can't read.
        if ($request->expectsJson()) {
            return new JsonResponse([
                'error' => 'ip_blocked',
                'reason' => 'operator_block',
            ], 403);
        }

        return response('Forbidden.', 403, ['Content-Type' => 'text/plain']);
    }
}
