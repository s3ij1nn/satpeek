<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Probes a destination URL to predict whether it can be loaded inside
 * SatPeek's `<iframe>` PTC viewer (display_mode=iframe). The advertiser
 * picks display mode at submission time; without this probe they only
 * learn the iframe is broken when their first viewer hits a blank PTC
 * page (and a refund/rejection round-trip).
 *
 * Detection is intentionally conservative — false negatives (we say
 * "embeddable" when the real page blocks) are recoverable via the
 * normal rejection flow; false positives (we warn when it would
 * actually work) just nudge the advertiser to use `window` mode,
 * which is also a fine outcome.
 *
 * Signals checked:
 *   1. `X-Frame-Options` header — any value (DENY / SAMEORIGIN /
 *      ALLOW-FROM) blocks third-party embedding.
 *   2. `Content-Security-Policy` header containing a
 *      `frame-ancestors` directive — if the directive excludes `*` /
 *      `https:` and doesn't list our SatPeek origin, embedding fails.
 *
 * Failures (DNS, timeout, 4xx/5xx) return `embeddable=true` with
 * `reason='probe_failed'` so we don't block submission on a transient
 * upstream blip; the advertiser still gets a heads-up that we couldn't
 * verify.
 */
class IframeEmbedProbe
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @return array{embeddable: bool, blocker: ?string, detail: ?string}
     */
    public function probe(string $url, int $timeoutSeconds = 5): array
    {
        try {
            // HEAD first — most servers respect it and respond with the
            // same security headers as GET. Some misconfigured CDNs 405
            // on HEAD; fall back to a no-body GET so we still get headers.
            $response = $this->http
                ->withUserAgent('SatPeek-IframeProbe/1.0 (+https://satpeek.com)')
                ->timeout($timeoutSeconds)
                ->head($url);
            if ($response->status() === 405) {
                $response = $this->http
                    ->withUserAgent('SatPeek-IframeProbe/1.0 (+https://satpeek.com)')
                    ->timeout($timeoutSeconds)
                    ->get($url);
            }
        } catch (ConnectionException $e) {
            return [
                'embeddable' => true, // unknown — don't block submission
                'blocker' => 'probe_failed',
                'detail' => 'Network error reaching the URL: '.$e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'embeddable' => true,
                'blocker' => 'probe_failed',
                'detail' => 'Probe error: '.$e->getMessage(),
            ];
        }

        if (! $response->successful()) {
            return [
                'embeddable' => true,
                'blocker' => 'probe_failed',
                'detail' => 'Upstream returned HTTP '.$response->status().'; could not verify embeddability.',
            ];
        }

        // X-Frame-Options: DENY / SAMEORIGIN / ALLOW-FROM all block
        // cross-origin embedding from our domain. We treat any non-empty
        // value as blocking — even ALLOW-FROM is effectively dead in
        // modern browsers (deprecated in favour of CSP frame-ancestors).
        $xfo = trim((string) $response->header('X-Frame-Options'));
        if ($xfo !== '') {
            return [
                'embeddable' => false,
                'blocker' => 'x_frame_options',
                'detail' => 'Server sends X-Frame-Options: '.$xfo,
            ];
        }

        // CSP frame-ancestors. We don't try to fully parse the directive
        // (host-source matching against our exact origin would need the
        // public APP_URL host) — we just look for restrictive values:
        // `'none'`, `'self'` without our origin, or any explicit
        // host-source list. A wildcard `*` or `https:` is permissive
        // and we accept it.
        $csp = trim((string) $response->header('Content-Security-Policy'));
        if ($csp !== '') {
            $directive = self::extractFrameAncestors($csp);
            if ($directive !== null) {
                $tokens = preg_split('/\s+/', trim($directive)) ?: [];
                $tokens = array_map('strtolower', array_filter($tokens, fn ($t) => $t !== ''));
                // Permissive set: full wildcard or scheme-only allowance.
                $permissive = in_array('*', $tokens, true)
                    || in_array('https:', $tokens, true)
                    || in_array('http:', $tokens, true);
                if (! $permissive) {
                    return [
                        'embeddable' => false,
                        'blocker' => 'csp_frame_ancestors',
                        'detail' => "Server sends Content-Security-Policy: frame-ancestors {$directive}",
                    ];
                }
            }
        }

        return ['embeddable' => true, 'blocker' => null, 'detail' => null];
    }

    /**
     * Pull the value of `frame-ancestors` out of a CSP header string.
     * Returns null when the directive is absent.
     */
    private static function extractFrameAncestors(string $csp): ?string
    {
        foreach (explode(';', $csp) as $directive) {
            $directive = trim($directive);
            if ($directive === '') {
                continue;
            }
            if (preg_match('/^frame-ancestors\s+(.+)$/i', $directive, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }
}
