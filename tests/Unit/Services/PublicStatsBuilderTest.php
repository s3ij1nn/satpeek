<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\CaptchaChallenge;
use App\Models\InternalArticle;
use App\Models\PtcAd;
use App\Models\ShortlinkProviderCredential;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\PublicStatsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Locks the public-landing stats contract:
 *   - total_sat_paid sums sent withdrawals only (failed / hold / queued
 *     stay out — those are not "paid")
 *   - active_inventory counts approved+active PtcAds + token-set+active
 *     ShortlinkProviderCredentials + active InternalArticles
 *   - bot_rejection_rate = rejected / (verified+rejected+consumed) over
 *     the last 30 days; returns 0.0 on empty so the view never sees NaN
 *   - cache key shards by month so an operator pruning old rows
 *     doesn't see a stale cached value forever
 */
class PublicStatsBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_zero_state_returns_safe_defaults(): void
    {
        $stats = (new PublicStatsBuilder)->build();

        $this->assertSame(0, $stats['total_sat_paid']);
        $this->assertSame(0, $stats['active_inventory']);
        $this->assertSame(0.0, $stats['bot_rejection_rate']);
        $this->assertSame(0, $stats['captcha_attempts_30d']);
    }

    public function test_total_sat_paid_sums_sent_withdrawals_only(): void
    {
        $u = User::factory()->create();
        Withdrawal::create(['user_id' => $u->id, 'amount_sat' => 1000, 'faucetpay_email' => 'a@x', 'currency' => 'BTC', 'status' => 'sent']);
        Withdrawal::create(['user_id' => $u->id, 'amount_sat' => 2500, 'faucetpay_email' => 'a@x', 'currency' => 'BTC', 'status' => 'sent']);
        // Non-sent must NOT contribute.
        Withdrawal::create(['user_id' => $u->id, 'amount_sat' => 9999, 'faucetpay_email' => 'a@x', 'currency' => 'BTC', 'status' => 'failed']);
        Withdrawal::create(['user_id' => $u->id, 'amount_sat' => 7500, 'faucetpay_email' => 'a@x', 'currency' => 'BTC', 'status' => 'queued']);
        Withdrawal::create(['user_id' => $u->id, 'amount_sat' => 5000, 'faucetpay_email' => 'a@x', 'currency' => 'BTC', 'status' => 'hold']);

        $stats = (new PublicStatsBuilder)->build();

        $this->assertSame(3500, $stats['total_sat_paid']);
    }

    public function test_active_inventory_sums_three_surfaces_with_proper_filters(): void
    {
        // Active PTC + Inactive PTC (must not count) + Pending PTC (must not count).
        PtcAd::create([
            'source' => 'mock', 'external_id' => 'a-'.uniqid(), 'title' => 'live',
            'target_url' => 'https://e', 'reward_sat' => 1, 'duration_sec' => 5,
            'daily_limit_per_user' => 5, 'is_active' => true, 'status' => 'approved',
        ]);
        PtcAd::create([
            'source' => 'mock', 'external_id' => 'b-'.uniqid(), 'title' => 'off',
            'target_url' => 'https://e', 'reward_sat' => 1, 'duration_sec' => 5,
            'daily_limit_per_user' => 5, 'is_active' => false, 'status' => 'approved',
        ]);
        PtcAd::create([
            'source' => 'user', 'external_id' => 'c-'.uniqid(), 'title' => 'pending',
            'target_url' => 'https://e', 'reward_sat' => 1, 'duration_sec' => 5,
            'daily_limit_per_user' => 5, 'is_active' => true, 'status' => 'pending_review',
        ]);

        // Shortlink: active+token vs active-no-token vs inactive+token.
        ShortlinkProviderCredential::create([
            'name' => 'mock', 'label' => 'mock', 'transport' => 'query',
            'api_base' => 'https://m', 'api_token' => 'tk', 'is_active' => true,
            'reward_sat' => 5, 'hold_seconds' => 5, 'daily_limit_per_user' => 5,
        ]);
        ShortlinkProviderCredential::create([
            'name' => 'cuty', 'label' => 'cuty', 'transport' => 'query',
            'api_base' => 'https://m', 'api_token' => null, 'is_active' => true,
            'reward_sat' => 5, 'hold_seconds' => 5, 'daily_limit_per_user' => 5,
        ]);
        ShortlinkProviderCredential::create([
            'name' => 'exe', 'label' => 'exe', 'transport' => 'query',
            'api_base' => 'https://m', 'api_token' => 'tk', 'is_active' => false,
            'reward_sat' => 5, 'hold_seconds' => 5, 'daily_limit_per_user' => 5,
        ]);

        InternalArticle::create([
            'title' => 'live', 'body' => 'b', 'reward_sat' => 1,
            'read_seconds' => 30, 'daily_limit_per_user' => 3, 'is_active' => true,
        ]);
        InternalArticle::create([
            'title' => 'off', 'body' => 'b', 'reward_sat' => 1,
            'read_seconds' => 30, 'daily_limit_per_user' => 3, 'is_active' => false,
        ]);

        $stats = (new PublicStatsBuilder)->build();

        $this->assertSame(3, $stats['active_inventory'], '1 PTC + 1 shortlink + 1 article');
    }

    public function test_bot_rejection_rate_only_counts_resolved_in_30d_window(): void
    {
        $now = Carbon::now();

        // 3 resolved in window: 2 rejected, 1 verified → 66.7% rate.
        $this->seedResolved('rejected', $now->copy()->subDays(2));
        $this->seedResolved('rejected', $now->copy()->subDays(5));
        $this->seedResolved('verified', $now->copy()->subDays(10));
        // Out-of-window rejected — must not contribute.
        $this->seedResolved('rejected', $now->copy()->subDays(45));
        // Issued (still in flight) — must not contribute either.
        $this->seedResolved('issued', $now->copy()->subDays(1));

        $stats = (new PublicStatsBuilder)->build();

        $this->assertSame(3, $stats['captcha_attempts_30d']);
        $this->assertEqualsWithDelta(0.667, $stats['bot_rejection_rate'], 0.001);
    }

    public function test_cache_returns_same_payload_within_window(): void
    {
        $u = User::factory()->create();
        Withdrawal::create(['user_id' => $u->id, 'amount_sat' => 100, 'faucetpay_email' => 'a@x', 'currency' => 'BTC', 'status' => 'sent']);

        $first = (new PublicStatsBuilder)->build();

        // Add another sent row — but the cached response must NOT update.
        Withdrawal::create(['user_id' => $u->id, 'amount_sat' => 999, 'faucetpay_email' => 'a@x', 'currency' => 'BTC', 'status' => 'sent']);

        $second = (new PublicStatsBuilder)->build();

        $this->assertSame($first['total_sat_paid'], $second['total_sat_paid']);
    }

    private function seedResolved(string $status, Carbon $when): void
    {
        $row = CaptchaChallenge::create([
            'challenge_id' => 'cc_'.uniqid(),
            'user_id' => null,
            'session_id' => 'test',
            'provider' => 'trajectory_trace',
            'seed' => 'seed',
            'expected_shape' => [],
            'fingerprint_hash' => null,
            'client_ip' => '127.0.0.1',
            'ja4' => null,
            'user_agent' => 'phpunit',
            'status' => $status,
            'issued_at' => $when->copy()->subSeconds(5),
            'expires_at' => $when->copy()->addSeconds(55),
            'resolved_at' => $when,
        ]);
        // Backdate `created_at` so the 30-day window query works against
        // the actual row insertion time, not the test-runtime now().
        $row->forceFill([
            'created_at' => $when,
            'updated_at' => $when,
        ])->save();
    }
}
