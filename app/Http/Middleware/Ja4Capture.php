<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Normalises the upstream JA4 TLS fingerprint into one canonical request
 * header (`X-SP-JA4`) so app-layer code (ChallengeBuilder, signals, audit
 * logs) reads a single name regardless of which proxy emitted it.
 *
 * Header precedence (highest first):
 *   1. cf-ja4         — Cloudflare's native header for orange-cloud sites
 *   2. x-tls-ja4      — common nginx-module-ja4 / haproxy convention
 *   3. x-ja4          — generic
 *   4. x-sp-ja4       — already-normalised value from another middleware
 *
 * Anything that doesn't match the JA4 shape (`tNN[d|q]\d+_<hex>_<hex>`) is
 * dropped silently. A client spoofing a header can't flood the database
 * with garbage values, but a real Cloudflare-stamped JA4 is preserved.
 *
 * Note: this middleware does not REPLACE the trust-the-proxy story. If the
 * deployment doesn't have a TLS-terminating proxy that injects JA4, the
 * header will simply stay null and the TlsFingerprintSignal degrades to
 * "no signal" instead of producing false positives.
 */
class Ja4Capture
{
    /** Upstream headers in the order we trust them. */
    private const SOURCES = ['cf-ja4', 'x-tls-ja4', 'x-ja4', 'x-sp-ja4'];

    /**
     * Conservative JA4 shape — covers ja4 (TCP/TLS), ja4q (QUIC), and the
     * common ALPN/extension suffixes (`h2`, `h3`, `00`, …). The two 12-hex
     * digests are mandatory; the prefix portion stays permissive so we
     * don't reject novel-but-real combinations from new browsers.
     *
     * Reference example: `t13d1517h2_8daaf6152771_b186095e22b6`
     */
    private const SHAPE = '/^[a-z0-9]{6,20}_[a-f0-9]{12}_[a-f0-9]{12}$/i';

    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::SOURCES as $name) {
            $candidate = trim((string) $request->headers->get($name, ''));
            if ($candidate === '') {
                continue;
            }
            if (! preg_match(self::SHAPE, $candidate)) {
                continue;
            }
            // Lower-case the digest portion so equality + index lookups in
            // captcha_challenges.ja4 are stable regardless of upstream casing.
            $request->headers->set('X-SP-JA4', strtolower($candidate));
            return $next($request);
        }

        // No upstream header → leave X-SP-JA4 unset. ChallengeBuilder
        // already treats blank as "no signal" rather than logging null garbage.
        return $next($request);
    }
}
