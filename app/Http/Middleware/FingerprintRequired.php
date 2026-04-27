<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FingerprintRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((string) $request->header('X-SP-Fingerprint', '') === '') {
            return response()->json(['error' => 'fingerprint_required'], 400);
        }

        return $next($request);
    }
}
