<?php

namespace Tests\Feature\Ptc;

use App\Captcha\TrajectoryTraceProvider;
use App\Enums\EarnSessionStatus;
use App\Models\CaptchaChallenge;
use App\Models\PtcAd;
use App\Models\PtcView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Mirrors the shortlink AuthLandingTest for the PTC viewer.
 * Locks the per-watch /ptc/auth/{token} contract:
 *   - URL slug rotates per watch (each /start mints a fresh epoch_token)
 *   - landing page is owner-scoped (404 cross-user)
 *   - already-resolved view 410s
 *   - token-keyed heartbeat + complete endpoints behave like the legacy
 *     numeric-viewId ones but resolve by epoch_token
 */
class AuthLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('satpeek.captcha', [
            'ttl_ms' => 60000,
            'min_solve_ms' => 800,
            'max_solve_ms' => 60000,
            'min_points' => 20,
            'max_points' => 2000,
            'shape_tolerance_px' => 48.0,
            'expected_dt_median_ms_min' => 8,
            'expected_dt_median_ms_max' => 80,
            'min_dt_jitter_ratio' => 0.10,
            'min_completion_dwell_ms' => 100,
            'completion_dwell_radius_px' => 8.0,
            'min_jerk_entropy' => 1.2,
        ]);
    }

    public function test_each_start_yields_a_unique_auth_url_token(): void
    {
        $user = User::factory()->create();
        $ad = $this->seedAd();

        $first = $this->actingAs($user)->postJson("/api/ptc/{$ad->id}/start")->json();
        $second = $this->actingAs($user)->postJson("/api/ptc/{$ad->id}/start")->json();

        $this->assertNotSame($first['epoch_token'], $second['epoch_token']);
        $this->assertMatchesRegularExpression('/^pv_[a-z0-9]{28}$/', $first['epoch_token']);
        $this->assertMatchesRegularExpression('/^pv_[a-z0-9]{28}$/', $second['epoch_token']);
    }

    public function test_landing_page_renders_for_owning_user_with_pending_view(): void
    {
        $user = User::factory()->create();
        $view = $this->seedView(['user_id' => $user->id, 'status' => 'pending']);

        $response = $this->actingAs($user)->get(route('ptc.auth', ['token' => $view->epoch_token]));

        $response->assertOk();
        // Token must be embedded in the page so the JS skips the start API call.
        $response->assertSee($view->epoch_token, false);
    }

    public function test_landing_page_404s_for_other_users_token(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $view = $this->seedView(['user_id' => $owner->id]);

        $this->actingAs($stranger)
            ->get(route('ptc.auth', ['token' => $view->epoch_token]))
            ->assertNotFound();
    }

    public function test_landing_page_410s_for_already_resolved_view(): void
    {
        $user = User::factory()->create();
        $view = $this->seedView([
            'user_id' => $user->id,
            'status' => 'verified',
            'completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('ptc.auth', ['token' => $view->epoch_token]))
            ->assertStatus(410);
    }

    public function test_token_endpoint_heartbeat_increments(): void
    {
        $user = User::factory()->create();
        $view = $this->seedView(['user_id' => $user->id]);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->postJson(
                "/api/ptc/auth/{$view->epoch_token}/heartbeat",
                ['epoch_token' => $view->epoch_token, 'beacon_at_ms' => now()->valueOf() + $i * 1500],
            )->assertOk();
        }
        $this->assertSame(3, (int) PtcView::find($view->id)->heartbeats_received);
    }

    public function test_token_endpoint_complete_credits_balance(): void
    {
        $user = User::factory()->create(['balance_sat' => 0, 'total_earned_sat' => 0]);
        $ad = $this->seedAd(['reward_sat' => 23, 'duration_sec' => 5]);
        $view = $this->seedView([
            'user_id' => $user->id,
            'ptc_ad_id' => $ad->id,
            'started_at' => Carbon::now()->subSeconds($ad->duration_sec + 2),
            'heartbeats_expected' => 3,
            'heartbeats_received' => 3,
        ]);
        $challenge = $this->seedChallenge($user);
        $challenge->update(['status' => 'verified']);

        $response = $this->actingAs($user)->postJson(
            "/api/ptc/auth/{$view->epoch_token}/complete",
            ['epoch_token' => $view->epoch_token, 'captcha_challenge_id' => $challenge->challenge_id],
        );

        $response->assertOk()->assertJson(['ok' => true, 'reward_sat' => 23]);
        $this->assertSame(23, (int) $user->fresh()->balance_sat);
        $this->assertSame(EarnSessionStatus::Verified, PtcView::find($view->id)->status);
    }

    public function test_token_endpoint_404s_for_other_users_token(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $view = $this->seedView(['user_id' => $owner->id]);

        $this->actingAs($stranger)->postJson(
            "/api/ptc/auth/{$view->epoch_token}/heartbeat",
            ['epoch_token' => $view->epoch_token, 'beacon_at_ms' => now()->valueOf()],
        )->assertStatus(404)->assertJson(['error' => 'view_not_found']);

        $this->actingAs($stranger)->postJson(
            "/api/ptc/auth/{$view->epoch_token}/complete",
            ['epoch_token' => $view->epoch_token, 'captcha_challenge_id' => 'cc_x'],
        )->assertStatus(404)->assertJson(['error' => 'view_not_found']);
    }

    private function seedAd(array $overrides = []): PtcAd
    {
        return PtcAd::create(array_merge([
            'user_id' => null,
            'source' => 'mock',
            'external_id' => 'ad-auth-'.uniqid(),
            'title' => 'Auth landing test',
            'description' => null,
            'target_url' => 'https://example.com/ad',
            'display_mode' => 'window',
            'reward_sat' => 5,
            'cost_per_view_sat' => 0,
            'total_views_purchased' => 0,
            'views_remaining' => 0,
            'total_cost_sat' => 0,
            'duration_sec' => 5,
            'daily_limit_per_user' => 5,
            'is_active' => true,
            'status' => 'approved',
        ], $overrides));
    }

    private function seedView(array $overrides): PtcView
    {
        $defaults = [
            'epoch_token' => 'pv_'.bin2hex(random_bytes(14)),
            'status' => 'pending',
            'started_at' => Carbon::now(),
            'heartbeats_expected' => 3,
            'heartbeats_received' => 0,
        ];
        if (! isset($overrides['ptc_ad_id'])) {
            $overrides['ptc_ad_id'] = $this->seedAd()->id;
        }

        return PtcView::create(array_merge($defaults, $overrides));
    }

    private function seedChallenge(?User $user = null): CaptchaChallenge
    {
        $shape = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 40, 2, 8000, 60);
        $issuedAt = Carbon::now()->subSeconds(3);

        return CaptchaChallenge::create([
            'challenge_id' => 'cc_test_'.uniqid(),
            'user_id' => $user?->id,
            'session_id' => 'test',
            'provider' => 'trajectory_trace',
            'seed' => 'test-seed',
            'expected_shape' => $shape,
            'fingerprint_hash' => null,
            'client_ip' => '127.0.0.1',
            'ja4' => null,
            'user_agent' => 'phpunit',
            'status' => 'issued',
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt->copy()->addSeconds(60),
        ]);
    }
}
