<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\CloudflareClientIp;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Locks the Cloudflare-aware client-IP resolution.
 *
 * Without this middleware, every IP-consuming code path (bot detection,
 * captcha trace fingerprint locking, BitcoTask offer URL path segment,
 * webhook IP allow-list) sees Cloudflare's edge IP rather than the
 * visitor — silently breaking each defence. The contract pinned here:
 *
 *   - Off by default. `TRUST_CLOUDFLARE_PROXY=false` (or unset) makes the
 *     middleware a no-op; non-Cloudflare deployments keep working.
 *   - When ON: CF-Connecting-IP overwrites REMOTE_ADDR so downstream
 *     `request()->ip()` returns the visitor.
 *   - Garbage CF-Connecting-IP values (empty, malformed) are ignored —
 *     the connection IP wins, so the platform never falls back to a
 *     spoofable / unparseable value.
 */
class CloudflareClientIpTest extends TestCase
{
    public function test_disabled_passes_through_unchanged(): void
    {
        config()->set('satpeek.cloudflare.trust_proxy', false);

        $request = $this->makeRequest(connectionIp: '198.51.100.10', cfHeader: '203.0.113.50');

        $observed = $this->dispatch(new CloudflareClientIp, $request);

        $this->assertSame('198.51.100.10', $observed);
    }

    public function test_enabled_promotes_cf_connecting_ip_to_request_ip(): void
    {
        config()->set('satpeek.cloudflare.trust_proxy', true);

        $request = $this->makeRequest(connectionIp: '198.51.100.10', cfHeader: '203.0.113.50');

        $observed = $this->dispatch(new CloudflareClientIp, $request);

        $this->assertSame('203.0.113.50', $observed);
    }

    public function test_enabled_with_no_header_keeps_connection_ip(): void
    {
        config()->set('satpeek.cloudflare.trust_proxy', true);

        $request = $this->makeRequest(connectionIp: '198.51.100.10', cfHeader: null);

        $observed = $this->dispatch(new CloudflareClientIp, $request);

        $this->assertSame('198.51.100.10', $observed);
    }

    public function test_enabled_with_garbage_header_keeps_connection_ip(): void
    {
        config()->set('satpeek.cloudflare.trust_proxy', true);

        $request = $this->makeRequest(connectionIp: '198.51.100.10', cfHeader: 'not-an-ip');

        $observed = $this->dispatch(new CloudflareClientIp, $request);

        $this->assertSame('198.51.100.10', $observed);
    }

    public function test_enabled_handles_ipv6_cf_connecting_ip(): void
    {
        config()->set('satpeek.cloudflare.trust_proxy', true);

        $request = $this->makeRequest(connectionIp: '198.51.100.10', cfHeader: '2001:db8::42');

        $observed = $this->dispatch(new CloudflareClientIp, $request);

        $this->assertSame('2001:db8::42', $observed);
    }

    private function makeRequest(string $connectionIp, ?string $cfHeader): Request
    {
        $server = ['REMOTE_ADDR' => $connectionIp];
        if ($cfHeader !== null) {
            $server['HTTP_CF_CONNECTING_IP'] = $cfHeader;
        }

        return Request::create('/x', 'GET', server: $server);
    }

    /**
     * Runs the middleware and returns whatever `request()->ip()` resolves to
     * inside the next-step closure — the value every downstream consumer
     * (controllers, signals, adapters) would see.
     */
    private function dispatch(CloudflareClientIp $mw, Request $request): string
    {
        $observed = '';
        $response = $mw->handle($request, function (Request $r) use (&$observed): Response {
            $observed = (string) $r->ip();

            return new Response('ok');
        });
        $this->assertSame(200, $response->getStatusCode());

        return $observed;
    }
}
