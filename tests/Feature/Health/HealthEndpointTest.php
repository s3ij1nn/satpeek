<?php

namespace Tests\Feature\Health;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Locks the JSON shape + status-code contract of /up so external monitors
 * (load balancer, uptime probe, dashboard) can rely on it without breaking
 * silently when an internal refactor reshuffles fields.
 */
class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_shape_is_stable(): void
    {
        // Make Redis a no-op so the test environment doesn't need a live
        // Redis instance — the shape contract is what we're pinning here,
        // not the actual reachability.
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);

        $response = $this->getJson('/up');

        $response->assertJsonStructure([
            'status',
            'time',
            'checks' => [
                'database' => ['status', 'critical'],
                'redis' => ['status', 'critical'],
                'maxmind_asn' => ['status', 'critical'],
                'shortlink_providers' => ['status', 'critical'],
                'ip_reputation_providers' => ['status', 'critical'],
            ],
        ]);
        $this->assertContains($response->json('status'), ['ok', 'degraded', 'down']);
    }

    public function test_status_ok_returns_http_200(): void
    {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn('+PONG');
        // Force every optional component to "ok" so the overall is `ok`.
        config()->set('satpeek.ip_reputation.maxmind.asn_db', __FILE__); // any existing file
        config()->set('satpeek.ip_reputation.iphub.api_key', 'fake');
        config()->set('satpeek.shortlink_providers.btcut.api_token', 'fake');
        $this->app->forgetInstance(\App\Shortlinks\ShortlinkProviderRegistry::class);

        $response = $this->getJson('/up');

        $response->assertStatus(200);
        $this->assertSame('ok', $response->json('status'));
        $this->assertSame('ok', $response->json('checks.database.status'));
        $this->assertSame('ok', $response->json('checks.redis.status'));
        $this->assertSame('ok', $response->json('checks.maxmind_asn.status'));
    }

    public function test_unconfigured_maxmind_yields_degraded_overall_with_200(): void
    {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);
        config()->set('satpeek.ip_reputation.maxmind.asn_db', '');

        $response = $this->getJson('/up');

        $response->assertStatus(200);
        $this->assertSame('degraded', $response->json('checks.maxmind_asn.status'));
        $this->assertSame('unconfigured', $response->json('checks.maxmind_asn.detail'));
        $this->assertSame('degraded', $response->json('status'));
    }

    public function test_maxmind_path_set_but_file_missing_yields_down_not_degraded(): void
    {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);
        config()->set('satpeek.ip_reputation.maxmind.asn_db', '/var/this/path/does/not/exist.mmdb');

        $response = $this->getJson('/up');

        // MaxMind is not critical → still 200 even when "down".
        $response->assertStatus(200);
        $this->assertSame('down', $response->json('checks.maxmind_asn.status'));
        $this->assertSame('file_missing', $response->json('checks.maxmind_asn.detail'));
        $this->assertSame('degraded', $response->json('status'));
    }

    public function test_redis_unreachable_returns_503_with_status_down(): void
    {
        // Simulate Redis throwing on ping — this is the "critical down" path.
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andThrow(new \RuntimeException('CONNREFUSED'));

        $response = $this->getJson('/up');

        $response->assertStatus(503);
        $this->assertSame('down', $response->json('status'));
        $this->assertSame('down', $response->json('checks.redis.status'));
        $this->assertTrue($response->json('checks.redis.critical'));
    }

    public function test_shortlink_providers_count_reflects_configured_tokens(): void
    {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);
        // Three providers, one with a token.
        config()->set('satpeek.shortlink_providers', [
            'btcut' => ['api_base' => 'https://b/api', 'api_token' => 'tk'],
            'cuty'  => ['api_base' => 'https://c/api', 'api_token' => ''],
            'ouo'   => ['api_base' => 'https://o/api', 'api_token' => ''],
        ]);
        $this->app->forgetInstance(\App\Shortlinks\ShortlinkProviderRegistry::class);

        $response = $this->getJson('/up');

        $this->assertSame(1, $response->json('checks.shortlink_providers.configured'));
        $this->assertSame('ok', $response->json('checks.shortlink_providers.status'));
    }

    public function test_no_shortlink_providers_configured_marks_degraded(): void
    {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);
        config()->set('satpeek.shortlink_providers', [
            'btcut' => ['api_base' => 'https://b/api', 'api_token' => ''],
        ]);
        $this->app->forgetInstance(\App\Shortlinks\ShortlinkProviderRegistry::class);

        $response = $this->getJson('/up');

        $this->assertSame('degraded', $response->json('checks.shortlink_providers.status'));
        $this->assertSame('no_token_set', $response->json('checks.shortlink_providers.detail'));
    }
}
