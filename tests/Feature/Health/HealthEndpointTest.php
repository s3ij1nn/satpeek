<?php

namespace Tests\Feature\Health;

use App\Models\BotScoreHistory;
use App\Models\InternalArticle;
use App\Models\PtcAd;
use App\Models\ShortlinkProviderCredential;
use App\Models\User;
use App\Models\Withdrawal;
use App\Shortlinks\ShortlinkProviderRegistry;
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
                'offerwall_providers' => ['status', 'critical'],
                'faucetpay' => ['status', 'critical'],
                'bot_detection' => ['status', 'critical'],
                'earning_inventory' => ['status', 'critical'],
                'trusted_proxies' => ['status', 'critical'],
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
        config()->set('satpeek.offerwalls.enabled', ['bitcotask']);
        config()->set('satpeek.bitcotask.api_key', 'KEY');
        config()->set('satpeek.bitcotask.bearer_token', 'BEARER');
        config()->set('satpeek.bitcotask.s2s_secret', 'SECRET');
        config()->set('satpeek.faucetpay.api_key', 'FP-KEY');
        $this->app->forgetInstance(ShortlinkProviderRegistry::class);
        // Seed minimal earning inventory so the new earning_inventory check
        // doesn't drag the overall status to `degraded` here.
        InternalArticle::create([
            'title' => 'Smoke', 'body' => 'b',
            'reward_sat' => 1, 'read_seconds' => 30, 'daily_limit_per_user' => 3,
            'is_active' => true,
        ]);

        $response = $this->getJson('/up');

        $response->assertStatus(200);
        $this->assertSame('ok', $response->json('status'));
        $this->assertSame('ok', $response->json('checks.database.status'));
        $this->assertSame('ok', $response->json('checks.redis.status'));
        $this->assertSame('ok', $response->json('checks.maxmind_asn.status'));
        $this->assertSame('ok', $response->json('checks.offerwall_providers.status'));
        $this->assertSame('ok', $response->json('checks.faucetpay.status'));
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
            'cuty' => ['api_base' => 'https://c/api', 'api_token' => ''],
            'ouo' => ['api_base' => 'https://o/api', 'api_token' => ''],
        ]);
        $this->app->forgetInstance(ShortlinkProviderRegistry::class);

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
        $this->app->forgetInstance(ShortlinkProviderRegistry::class);

        $response = $this->getJson('/up');

        $this->assertSame('degraded', $response->json('checks.shortlink_providers.status'));
        $this->assertSame('no_token_set', $response->json('checks.shortlink_providers.detail'));
    }

    public function test_offerwall_providers_default_off_reports_unconfigured_not_down(): void
    {
        // Default ships with OFFERWALLS_ENABLED empty — the BitcoTasks
        // publisher review hasn't shipped credentials yet. /up must still
        // 200 on this path; "unconfigured" is not a paging condition.
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);
        config()->set('satpeek.offerwalls.enabled', []);

        $response = $this->getJson('/up');

        $response->assertStatus(200);
        $this->assertSame('degraded', $response->json('checks.offerwall_providers.status'));
        $this->assertSame('unconfigured', $response->json('checks.offerwall_providers.detail'));
        $this->assertFalse($response->json('checks.offerwall_providers.critical'));
    }

    public function test_offerwall_enabled_with_full_credentials_reports_ok(): void
    {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);
        config()->set('satpeek.offerwalls.enabled', ['bitcotask']);
        config()->set('satpeek.bitcotask.api_key', 'KEY');
        config()->set('satpeek.bitcotask.bearer_token', 'BEARER');
        config()->set('satpeek.bitcotask.s2s_secret', 'SECRET');

        $response = $this->getJson('/up');

        $this->assertSame('ok', $response->json('checks.offerwall_providers.status'));
        $this->assertSame(['bitcotask'], $response->json('checks.offerwall_providers.enabled'));
    }

    public function test_faucetpay_unconfigured_reports_degraded_not_down(): void
    {
        // FAUCETPAY_API_KEY blank — withdrawals would all permanent-fail,
        // but the platform itself stays up. Surface as degraded so dashboards
        // light up without paging on-call.
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);
        config()->set('satpeek.faucetpay.api_key', '');

        $response = $this->getJson('/up');

        $response->assertStatus(200);
        $this->assertSame('degraded', $response->json('checks.faucetpay.status'));
        $this->assertSame('unconfigured', $response->json('checks.faucetpay.detail'));
        $this->assertFalse($response->json('checks.faucetpay.critical'));
    }

    public function test_faucetpay_backlog_older_than_one_hour_reports_degraded_with_count(): void
    {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);
        config()->set('satpeek.faucetpay.api_key', 'FP-KEY');

        $u = User::factory()->create();
        // Three queued + one that's already moved on. Backdate two of the
        // queued rows past the 1-h threshold; the third is fresh and must
        // not count.
        foreach (range(1, 3) as $_) {
            Withdrawal::create([
                'user_id' => $u->id, 'amount_sat' => 1000,
                'faucetpay_email' => 'a@x', 'currency' => 'BTC',
                'status' => 'queued',
            ]);
        }
        // Backdate the first two so they pre-date the 1-h cutoff.
        Withdrawal::query()
            ->where('user_id', $u->id)
            ->where('status', 'queued')
            ->orderBy('id')
            ->limit(2)
            ->update(['created_at' => now()->subHours(2)]);

        $response = $this->getJson('/up');

        $response->assertStatus(200);
        $this->assertSame('degraded', $response->json('checks.faucetpay.status'));
        $this->assertSame('backlogged', $response->json('checks.faucetpay.detail'));
        $this->assertSame(2, $response->json('checks.faucetpay.backlog'));
    }

    public function test_faucetpay_fresh_queue_reports_ok(): void
    {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);
        config()->set('satpeek.faucetpay.api_key', 'FP-KEY');

        $u = User::factory()->create();
        // Recently-queued row — within the 1-h grace, queue worker has
        // a chance to drain it.
        Withdrawal::create([
            'user_id' => $u->id, 'amount_sat' => 1000,
            'faucetpay_email' => 'a@x', 'currency' => 'BTC',
            'status' => 'queued',
        ]);

        $response = $this->getJson('/up');

        $this->assertSame('ok', $response->json('checks.faucetpay.status'));
    }

    public function test_offerwall_enabled_but_missing_bearer_token_reports_degraded_with_field_list(): void
    {
        // Operator flipped OFFERWALLS_ENABLED on but pasted only the api_key —
        // adapter would silently fetch [] from every endpoint. /up surfaces
        // exactly which env vars are still empty.
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);
        config()->set('satpeek.offerwalls.enabled', ['bitcotask']);
        config()->set('satpeek.bitcotask.api_key', 'KEY');
        config()->set('satpeek.bitcotask.bearer_token', '');
        config()->set('satpeek.bitcotask.s2s_secret', '');

        $response = $this->getJson('/up');

        $response->assertStatus(200);
        $this->assertSame('degraded', $response->json('checks.offerwall_providers.status'));
        $this->assertSame('credentials_missing', $response->json('checks.offerwall_providers.detail'));
        $this->assertSame(
            ['bitcotask:bearer_token', 'bitcotask:s2s_secret'],
            $response->json('checks.offerwall_providers.missing'),
        );
    }

    public function test_bot_detection_no_users_yet_reports_ok(): void
    {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);

        $response = $this->getJson('/up');

        $response->assertStatus(200);
        $this->assertSame('ok', $response->json('checks.bot_detection.status'));
        $this->assertSame('no_users_yet', $response->json('checks.bot_detection.detail'));
    }

    public function test_bot_detection_users_present_but_no_recent_evaluations_reports_degraded(): void
    {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);
        User::factory()->create();

        $response = $this->getJson('/up');

        $response->assertStatus(200);
        $this->assertSame('degraded', $response->json('checks.bot_detection.status'));
        $this->assertSame('no_evaluations_24h', $response->json('checks.bot_detection.detail'));
        $this->assertSame(0, $response->json('checks.bot_detection.evaluations_24h'));
    }

    public function test_bot_detection_recent_evaluation_present_reports_ok(): void
    {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);
        $u = User::factory()->create();
        BotScoreHistory::create([
            'user_id' => $u->id, 'score' => 0.10, 'tier' => 'trust', 'signals' => [],
            'created_at' => now()->subHours(2),
        ]);

        $response = $this->getJson('/up');

        $response->assertStatus(200);
        $this->assertSame('ok', $response->json('checks.bot_detection.status'));
        $this->assertSame(1, $response->json('checks.bot_detection.evaluations_24h'));
    }

    public function test_earning_inventory_zero_active_reports_degraded(): void
    {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);

        $response = $this->getJson('/up');

        $response->assertStatus(200);
        $this->assertSame('degraded', $response->json('checks.earning_inventory.status'));
        $this->assertSame('no_inventory_active', $response->json('checks.earning_inventory.detail'));
        $this->assertSame(0, $response->json('checks.earning_inventory.ptc_ads'));
        $this->assertSame(0, $response->json('checks.earning_inventory.shortlink_providers'));
        $this->assertSame(0, $response->json('checks.earning_inventory.internal_articles'));
    }

    public function test_earning_inventory_with_active_ptc_ad_reports_ok(): void
    {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);
        PtcAd::create([
            'source' => 'mock', 'external_id' => 'ad-'.uniqid(),
            'title' => 'x', 'target_url' => 'https://e.x', 'reward_sat' => 1,
            'duration_sec' => 5, 'daily_limit_per_user' => 5,
            'is_active' => true, 'status' => 'approved',
        ]);

        $response = $this->getJson('/up');

        $response->assertStatus(200);
        $this->assertSame('ok', $response->json('checks.earning_inventory.status'));
        $this->assertSame(1, $response->json('checks.earning_inventory.ptc_ads'));
    }

    public function test_earning_inventory_counts_active_only(): void
    {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);
        // Inactive — must NOT count.
        PtcAd::create([
            'source' => 'mock', 'external_id' => 'ad-off-'.uniqid(),
            'title' => 'off', 'target_url' => 'https://e.x', 'reward_sat' => 1,
            'duration_sec' => 5, 'daily_limit_per_user' => 5,
            'is_active' => false, 'status' => 'approved',
        ]);
        // Active but pending — must NOT count (only `approved`).
        PtcAd::create([
            'source' => 'user', 'external_id' => 'ad-pending-'.uniqid(),
            'title' => 'pending', 'target_url' => 'https://e.x', 'reward_sat' => 1,
            'duration_sec' => 5, 'daily_limit_per_user' => 5,
            'is_active' => true, 'status' => 'pending_review',
        ]);
        InternalArticle::create([
            'title' => 'live article', 'body' => 'b',
            'reward_sat' => 1, 'read_seconds' => 30, 'daily_limit_per_user' => 3,
            'is_active' => true,
        ]);
        // Inactive shortlink credential (token set but is_active=false) must NOT count.
        ShortlinkProviderCredential::create([
            'name' => 'mock', 'label' => 'mock', 'transport' => 'query',
            'api_base' => 'https://m', 'api_token' => 'tk',
            'is_active' => false,
            'reward_sat' => 5, 'hold_seconds' => 5, 'daily_limit_per_user' => 5,
        ]);

        $response = $this->getJson('/up');

        $this->assertSame(0, $response->json('checks.earning_inventory.ptc_ads'));
        $this->assertSame(1, $response->json('checks.earning_inventory.internal_articles'));
        $this->assertSame(0, $response->json('checks.earning_inventory.shortlink_providers'));
        // 1 active overall → status ok
        $this->assertSame('ok', $response->json('checks.earning_inventory.status'));
    }

    public function test_trusted_proxies_unset_with_xff_header_present_reports_degraded(): void
    {
        // The silent-misconfiguration class: a CDN deployment forgets
        // to set TRUSTED_PROXIES, the framework discards X-Forwarded-*,
        // and every IP-keyed signal sees the CDN edge IP. The probe
        // catches the moment by detecting "XFF arriving but env empty".
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);

        // Force the env to empty even when local .env sets it.
        config(['app.env' => 'production']);
        $_ENV['TRUSTED_PROXIES'] = '';
        putenv('TRUSTED_PROXIES=');

        $response = $this->getJson('/up', ['X-Forwarded-For' => '203.0.113.10']);

        $this->assertSame('degraded', $response->json('checks.trusted_proxies.status'));
        $this->assertSame('proxy_unconfigured', $response->json('checks.trusted_proxies.detail'));
    }

    public function test_trusted_proxies_set_or_no_xff_reports_ok(): void
    {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);

        // No XFF on the inbound request → probe stays ok regardless of
        // env (single-tenant Docker without a proxy).
        $_ENV['TRUSTED_PROXIES'] = '';
        putenv('TRUSTED_PROXIES=');
        $response = $this->getJson('/up');
        $this->assertSame('ok', $response->json('checks.trusted_proxies.status'));

        // XFF present but env populated → operator wired the trust
        // correctly, no warning needed.
        $_ENV['TRUSTED_PROXIES'] = '*';
        putenv('TRUSTED_PROXIES=*');
        $response = $this->getJson('/up', ['X-Forwarded-For' => '203.0.113.10']);
        $this->assertSame('ok', $response->json('checks.trusted_proxies.status'));
    }
}
