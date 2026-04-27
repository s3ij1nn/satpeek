<?php

declare(strict_types=1);

namespace Tests\Unit\IpReputation;

use App\IpReputation\Adapters\IpHubProvider;
use App\IpReputation\Adapters\ProxyCheckProvider;
use App\IpReputation\ProviderRateLimit;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Locks the rate-limit-aware skip on ProxyCheck and IPHub. The contract
 * pinned here is what the user asked for:
 *
 *   - ProxyCheck stays the primary detector (registered first in the
 *     CompositeProvider chain) because its detection coverage is wider.
 *   - When ProxyCheck returns the documented `status: denied` body
 *     (quota exhausted), it marks itself rate-limited via the shared
 *     {@see ProviderRateLimit} cache marker and returns null — the
 *     CompositeProvider then walks to the next provider (IPHub) on its
 *     own, exactly the fallback the operator wanted.
 *   - While ProxyCheck is marked limited, subsequent lookups skip the
 *     API call entirely instead of burning round-trips that will all
 *     fail with the same `denied` response.
 *   - IPHub follows the same pattern on HTTP 429 / 403.
 */
class RateLimitFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_proxycheck_denied_response_marks_provider_limited(): void
    {
        $client = $this->fakeClient(new Response(200, [], json_encode([
            'status' => 'denied',
            'message' => 'rate limit exceeded',
        ])));
        $provider = new ProxyCheckProvider($client, apiKey: 'key', apiBase: 'https://proxycheck.io/v2');

        $this->assertFalse(ProviderRateLimit::isLimited('proxycheck'));
        $verdict = $provider->lookup('203.0.113.10');
        $this->assertNull($verdict);
        $this->assertTrue(ProviderRateLimit::isLimited('proxycheck'));
    }

    public function test_proxycheck_skips_http_call_when_marked_limited(): void
    {
        ProviderRateLimit::markLimited('proxycheck', 60);
        // The mock has zero queued responses — if the provider attempts an
        // HTTP call it would throw, so this tests that the skip happens
        // BEFORE any network attempt.
        $client = $this->fakeClient();
        $provider = new ProxyCheckProvider($client, apiKey: 'key', apiBase: 'https://proxycheck.io/v2');

        $this->assertNull($provider->lookup('203.0.113.10'));
    }

    public function test_proxycheck_normal_response_does_not_mark_limited(): void
    {
        $client = $this->fakeClient(new Response(200, [], json_encode([
            'status' => 'ok',
            '203.0.113.10' => [
                'proxy' => 'no',
                'type' => 'Residential',
            ],
        ])));
        $provider = new ProxyCheckProvider($client, apiKey: 'key', apiBase: 'https://proxycheck.io/v2');

        $verdict = $provider->lookup('203.0.113.10');
        $this->assertNotNull($verdict);
        $this->assertFalse(ProviderRateLimit::isLimited('proxycheck'));
    }

    public function test_iphub_429_marks_provider_limited(): void
    {
        $client = $this->fakeClient(new Response(429, [], 'Too Many Requests'));
        $provider = new IpHubProvider($client, apiKey: 'key', apiBase: 'https://v2.api.iphub.info');

        $this->assertNull($provider->lookup('203.0.113.10'));
        $this->assertTrue(ProviderRateLimit::isLimited('iphub'));
    }

    public function test_iphub_403_also_marks_limited(): void
    {
        $client = $this->fakeClient(new Response(403, [], 'Key disabled'));
        $provider = new IpHubProvider($client, apiKey: 'key', apiBase: 'https://v2.api.iphub.info');

        $this->assertNull($provider->lookup('203.0.113.10'));
        $this->assertTrue(ProviderRateLimit::isLimited('iphub'));
    }

    public function test_iphub_skips_http_call_when_marked_limited(): void
    {
        ProviderRateLimit::markLimited('iphub', 60);
        $client = $this->fakeClient();
        $provider = new IpHubProvider($client, apiKey: 'key', apiBase: 'https://v2.api.iphub.info');

        $this->assertNull($provider->lookup('203.0.113.10'));
    }

    public function test_iphub_500_does_not_mark_limited_only_quota_signals_do(): void
    {
        // 5xx is a transient upstream blip, NOT a quota signal. Don't burn
        // the cooldown window over it — let the next request retry.
        $client = $this->fakeClient(new Response(500, [], 'Server Error'));
        $provider = new IpHubProvider($client, apiKey: 'key', apiBase: 'https://v2.api.iphub.info');

        $this->assertNull($provider->lookup('203.0.113.10'));
        $this->assertFalse(ProviderRateLimit::isLimited('iphub'));
    }

    private function fakeClient(?Response $queued = null): Client
    {
        $mock = $queued ? new MockHandler([$queued]) : new MockHandler;

        return new Client(['handler' => HandlerStack::create($mock)]);
    }
}
