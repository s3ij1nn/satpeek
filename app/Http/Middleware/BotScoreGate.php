<?php

namespace App\Http\Middleware;

use App\BotDetection\PolicyEnforcer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BotScoreGate
{
    public function __construct(private readonly PolicyEnforcer $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->is_banned) {
            return response()->json(['error' => 'banned'], 403);
        }
        if ($user && $this->policy->tier($user) === 'banned') {
            return response()->json(['error' => 'tier_banned'], 403);
        }

        return $next($request);
    }
}
