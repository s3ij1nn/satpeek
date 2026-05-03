<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ShortlinkProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Locks the named per-IP / per-user throttle wiring across the API
 * surface. The exact thresholds live in
 * AppServiceProvider::registerRateLimiters() — these tests pin
 *
 *   1. that each named limiter is REGISTERED (so a future refactor
 *      can't silently drop one and turn the surface back into an
 *      open faucet)
 *   2. that the throttle middleware actually fires once you cross
 *      the limit (we exercise the smaller-cap limiters via real
 *      requests — the larger ones would take too long, and the
 *      Laravel framework guarantees the wiring is the same shape)
 *
 * The throttle middleware hashes the limiter's `by()` key with the
 * limiter name (`md5($limiterName.$limit->key)`) before storing it
 * in the cache. Pre-seeding the cache by hand is fragile against
 * Laravel internals; firing real requests through the route is the
 * stable contract.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Wipe ALL cache entries so a previous test's accrued throttle
        // counter doesn't bleed into ours. The throttle middleware uses
        // the default cache store.
        Cache::flush();
        // Belt-and-suspenders for the named-limit cleanup paths.
        foreach (['captcha-issue', 'captcha-verify', 'beacon', 'earning-start', 'withdraw', 'adblock-report'] as $n) {
            RateLimiter::clear($n);
        }
    }

    public function test_named_rate_limiters_are_all_registered(): void
    {
        // limiter() returns null if the name isn't registered. Guard
        // every named limit our routes depend on.
        foreach ([
            'captcha-issue',
            'captcha-verify',
            'beacon',
            'earning-start',
            'withdraw',
            'adblock-report',
        ] as $name) {
            $this->assertNotNull(
                RateLimiter::limiter($name),
                "Named rate limiter `{$name}` is not registered — routes referencing it will 500."
            );
        }
    }

    public function test_withdraw_throttle_blocks_after_5_per_minute_per_user(): void
    {
        $user = User::factory()->create(['balance_sat' => 50_000_000, 'email_verified_at' => now()]);

        // Fire 5 successful requests through the actual middleware so the
        // throttle counter advances to its cap. The 6th must 429.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson('/api/withdraw', [
                'amount_sat' => 1000,
                'faucetpay_email' => 'u@example.com',
                'currency' => 'BTC',
            ]);
        }

        $blocked = $this->actingAs($user)->postJson('/api/withdraw', [
            'amount_sat' => 1000,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
        ]);
        $blocked->assertStatus(429);
    }

    public function test_withdraw_throttle_is_per_user_not_global(): void
    {
        $u1 = User::factory()->create(['balance_sat' => 50_000_000, 'email_verified_at' => now()]);
        $u2 = User::factory()->create(['balance_sat' => 50_000_000, 'email_verified_at' => now()]);

        // Saturate u1's bucket.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($u1)->postJson('/api/withdraw', [
                'amount_sat' => 1000,
                'faucetpay_email' => 'a@example.com',
                'currency' => 'BTC',
            ]);
        }
        $this->actingAs($u1)->postJson('/api/withdraw', [
            'amount_sat' => 1000, 'faucetpay_email' => 'a@example.com', 'currency' => 'BTC',
        ])->assertStatus(429);

        // u2 must still be free — different user, different bucket.
        $u2Response = $this->actingAs($u2)->postJson('/api/withdraw', [
            'amount_sat' => 1000, 'faucetpay_email' => 'b@example.com', 'currency' => 'BTC',
        ]);
        $this->assertNotSame(429, $u2Response->getStatusCode(), 'per-user buckets must not bleed across users');
    }

    public function test_earning_start_throttle_blocks_after_30_per_minute_per_user(): void
    {
        $user = User::factory()->create();
        ShortlinkProviderCredential::create([
            'name' => 'mock', 'label' => 'mock', 'transport' => 'query',
            'api_base' => 'https://mock.test/api', 'api_token' => 'tk',
            'is_active' => true, 'reward_sat' => 5, 'hold_seconds' => 5,
            'daily_limit_per_user' => 9999,
        ]);

        // Fire 30 starts. Some will return 429 from earlier hits in the
        // sequence; what matters is the 31st request is definitively 429.
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($user)->postJson('/api/shortlinks/start/mock');
        }
        $blocked = $this->actingAs($user)->postJson('/api/shortlinks/start/mock');
        $blocked->assertStatus(429);
    }

    public function test_forgot_password_endpoint_is_throttled(): void
    {
        // Without the route-level `throttle:5,1`, an attacker can bomb
        // a target inbox / enumerate addresses via mail-job timing.
        // 5 requests must be allowed; the 6th must 429.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/forgot-password', ['email' => 'spam@example.com']);
        }

        $blocked = $this->postJson('/forgot-password', ['email' => 'spam@example.com']);
        $blocked->assertStatus(429);
    }
}
