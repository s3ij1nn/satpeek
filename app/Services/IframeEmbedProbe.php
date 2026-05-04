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
        // SSRF guard: validate the URL up-front. The probe is fed
        // attacker-controlled input from /advertise/create, so without
        // this check an authenticated user with a balance could point
        // it at file:// (LFI), gopher:// (smuggle to Redis), or
        // 169.254.169.254 (AWS IMDS). The probe runs server-side so
        // the request reaches the internal target even if the verdict
        // is later surfaced to the user.
        $rejection = self::rejectIfDangerousUrl($url);
        if ($rejection !== null) {
            return $rejection;
        }

        try {
            // HEAD first — most servers respect it and respond with the
            // same security headers as GET. Some misconfigured CDNs 405
            // on HEAD; fall back to a no-body GET so we still get headers.
            $response = $this->http
                ->withUserAgent('SatPeek-IframeProbe/1.0 (+https://satpeek.com)')
                ->timeout($timeoutSeconds)
                ->withOptions([
                    // Belt-and-suspenders: even if a future Guzzle
                    // upgrade adds new schemes, restrict redirects to
                    // http/https so a target server can't 302 us into
                    // file:// / ftp:// territory.
                    'allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']],
                ])
                ->head($url);
            if ($response->status() === 405) {
                $response = $this->http
                    ->withUserAgent('SatPeek-IframeProbe/1.0 (+https://satpeek.com)')
                    ->timeout($timeoutSeconds)
                    ->withOptions([
                        'allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']],
                    ])
                    ->get($url);
            }
        } catch (ConnectionException) {
            // Don't echo $e->getMessage() back to the advertiser —
            // when a probe accidentally reaches an internal service,
            // the connection error string would otherwise leak the
            // remote service's banner / response shape. Generic
            // message is enough for the operator-facing flash.
            return [
                'embeddable' => true,
                'blocker' => 'probe_failed',
                'detail' => 'Could not reach the URL.',
            ];
        } catch (\Throwable) {
            return [
                'embeddable' => true,
                'blocker' => 'probe_failed',
                'detail' => 'Probe failed.',
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
     * SSRF guard. Returns a probe-failed verdict when the URL targets
     * something the probe must not contact, otherwise null.
     *
     * Rejects:
     *   - Non-http/https schemes (file:// gopher:// dict:// etc)
     *   - URLs with no host
     *   - Hosts that resolve only to RFC-1918 / loopback / link-local
     *     / cloud-metadata / multicast / unspecified addresses
     *
     * The DNS rebinding window is small but real — between this check
     * and the actual HTTP request a hostile DNS server could swap the
     * answer to an internal IP. Mitigated by Guzzle's connection
     * timeout (5 s) shrinking the window AND by the fact that this
     * probe runs in the request handler (synchronous), not on a
     * scheduler — there's no long-lived workers an attacker could
     * race.
     *
     * @return array{embeddable: bool, blocker: ?string, detail: ?string}|null
     */
    private static function rejectIfDangerousUrl(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'])) {
            return [
                'embeddable' => true,
                'blocker' => 'probe_failed',
                'detail' => 'URL is malformed.',
            ];
        }
        // Scheme check BEFORE host check so `file:///etc/passwd`
        // (which has no host) is rejected with the scheme reason
        // rather than the generic malformed-URL one.
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return [
                'embeddable' => true,
                'blocker' => 'probe_failed',
                'detail' => 'Only http(s) URLs are probed.',
            ];
        }
        if (! isset($parts['host'])) {
            return [
                'embeddable' => true,
                'blocker' => 'probe_failed',
                'detail' => 'URL is malformed.',
            ];
        }

        // Resolve the host. gethostbynamel returns an array of IPv4
        // addresses or false on failure. We err strict: if ANY resolved
        // address is private, reject. This catches DNS records with
        // both public and private A records (a known SSRF dodge).
        $host = (string) $parts['host'];
        $addrs = self::resolveHost($host);
        if ($addrs === []) {
            return [
                'embeddable' => true,
                'blocker' => 'probe_failed',
                'detail' => 'Hostname did not resolve.',
            ];
        }
        foreach ($addrs as $addr) {
            if (! self::isPublicAddress($addr)) {
                return [
                    'embeddable' => true,
                    'blocker' => 'probe_failed',
                    'detail' => 'URL points to a non-public address.',
                ];
            }
        }

        return null;
    }

    /**
     * Resolve `$host` to a list of IPv4 + IPv6 addresses.
     * Returns [] on resolution failure or numeric-only host.
     *
     * Handles the literal-IP case directly so an attacker can't
     * skip the resolver by writing `http://127.0.0.1` instead of
     * `http://localhost`.
     *
     * @return array<int, string>
     */
    private static function resolveHost(string $host): array
    {
        // Literal-IP fast path. filter_var rejects any non-IP.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        // Strip surrounding [ ] for IPv6 literals.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $unwrapped = substr($host, 1, -1);
            if (filter_var($unwrapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                return [$unwrapped];
            }
        }

        $addrs = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $addrs = $v4;
        }
        // Include AAAA records too — IPv6 link-local (fe80::) and
        // unique-local (fc00::/7) are common SSRF targets in
        // dual-stacked datacenters.
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (! empty($r['ipv6'])) {
                    $addrs[] = $r['ipv6'];
                }
            }
        }

        return array_values(array_unique($addrs));
    }

    /**
     * True when `$addr` is a routable public address. Rejects every
     * range listed in RFC 1918, RFC 4193, RFC 5735, RFC 5737,
     * RFC 6890, plus the cloud-metadata special cases (169.254.169.254
     * is link-local but worth calling out — it's the AWS / GCP / Azure
     * IMDS endpoint that countless SSRF advisories pivot through).
     */
    private static function isPublicAddress(string $addr): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE blocks 10/8, 172.16/12, 192.168/16,
        // fc00::/7. FILTER_FLAG_NO_RES_RANGE blocks loopback, link-
        // local (169.254/16, fe80::/10), multicast, reserved, and the
        // 0.0.0.0 / ::1 / ::1 unspecified addresses. Together they're
        // the full RFC 6890 special-purpose set we care about.
        $public = filter_var(
            $addr,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        return $public !== false;
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
