<?php

namespace Tests\Feature\Ptc;

use App\Captcha\TrajectoryTraceProvider;
use App\Models\BalanceLedger;
use App\Models\CaptchaChallenge;
use App\Models\PtcAd;
use App\Models\PtcView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks down the PTC viewing round-trip:
 *   - viewer page renders the right UI for each `display_mode`
 *   - start → heartbeat → complete credits the user when the captcha verifies
 *   - the abuse-resistance guards (heartbeat deficit, too-fast complete) actually
 *     fire so a viewer can't claim without watching the timer
 */
class ViewerFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same captcha tolerances the running site uses; locked here so a
        // tightening of production defaults can't silently break the test.
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

    public function test_window_mode_renders_open_in_new_tab_cta(): void
    {
        $user = User::factory()->create();
        $ad = $this->seedAd(['display_mode' => 'window']);

        $response = $this->actingAs($user)->get('/ptc/'.$ad->id);

        $response->assertOk();
        $response->assertSee('id="openAdBtn"', false);
        $response->assertSee('Open the ad in a new tab', false);
        // Inline iframe must NOT be rendered in window mode.
        $response->assertDontSee('id="ptcIframe"', false);
    }

    public function test_iframe_mode_renders_inline_iframe(): void
    {
        $user = User::factory()->create();
        $ad = $this->seedAd(['display_mode' => 'iframe', 'target_url' => 'https://example.com/ad']);

        $response = $this->actingAs($user)->get('/ptc/'.$ad->id);

        $response->assertOk();
        $response->assertSee('id="ptcIframe"', false);
        $response->assertSee('frame--iframe', false);
        $response->assertSee('https://example.com/ad', false);
        // Window-mode CTA must NOT be rendered.
        $response->assertDontSee('id="openAdBtn"', false);
    }

    public function test_start_creates_pending_view_and_returns_token(): void
    {
        $user = User::factory()->create();
        $ad = $this->seedAd();

        $response = $this->actingAs($user)->postJson("/api/ptc/{$ad->id}/start");

        $response->assertOk();
        $response->assertJsonStructure(['view_id', 'epoch_token', 'redirect_url', 'duration_sec', 'heartbeats_expected']);
        $this->assertDatabaseHas('ptc_views', [
            'user_id' => $user->id,
            'ptc_ad_id' => $ad->id,
            'status' => 'pending',
        ]);
    }

    public function test_heartbeat_increments_received_count(): void
    {
        $user = User::factory()->create();
        $ad = $this->seedAd();

        $start = $this->actingAs($user)->postJson("/api/ptc/{$ad->id}/start")->json();
        $viewId = $start['view_id'];
        $token = $start['epoch_token'];

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->postJson("/api/ptc/{$viewId}/heartbeat", [
                'epoch_token' => $token,
                'beacon_at_ms' => now()->valueOf() + $i * 1500,
            ])->assertOk();
        }

        $this->assertSame(3, (int) PtcView::find($viewId)->heartbeats_received);
    }

    public function test_complete_credits_balance_when_captcha_passes(): void
    {
        $user = User::factory()->create(['balance_sat' => 0, 'total_earned_sat' => 0]);
        $ad = $this->seedAd(['reward_sat' => 17, 'duration_sec' => 5]);

        $start = $this->actingAs($user)->postJson("/api/ptc/{$ad->id}/start")->json();
        $viewId = $start['view_id'];
        $token = $start['epoch_token'];

        // Submit the expected number of heartbeats so the deficit guard passes.
        for ($i = 0; $i < $start['heartbeats_expected']; $i++) {
            $this->actingAs($user)->postJson("/api/ptc/{$viewId}/heartbeat", [
                'epoch_token' => $token,
                'beacon_at_ms' => now()->valueOf() + $i * 1500,
            ])->assertOk();
        }

        // Backdate started_at so the elapsed-vs-duration check sees a full watch.
        PtcView::where('id', $viewId)->update([
            'started_at' => Carbon::now()->subSeconds($ad->duration_sec + 2),
        ]);

        [$challenge, $shape] = $this->seedChallenge();
        $points = $this->humanLikeTrace($shape);

        // Pre-verify the captcha (the viewer JS does this via /api/captcha/verify
        // before posting /complete; here we mark it verified directly so the test
        // focuses on the complete handler's contract).
        $challenge->update(['status' => 'verified']);

        $response = $this->actingAs($user)->postJson("/api/ptc/{$viewId}/complete", [
            'epoch_token' => $token,
            'captcha_challenge_id' => $challenge->challenge_id,
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'reward_sat' => 17]);
        $this->assertSame(17, (int) $user->fresh()->balance_sat);
        $this->assertSame(17, (int) $user->fresh()->total_earned_sat);
        $this->assertSame('verified', PtcView::find($viewId)->status);
        $this->assertDatabaseHas('balance_ledgers', [
            'user_id' => $user->id,
            'delta_sat' => 17,
            'reason' => 'ptc_view',
        ]);
    }

    public function test_complete_rejects_when_heartbeats_too_few(): void
    {
        $user = User::factory()->create(['balance_sat' => 0]);
        $ad = $this->seedAd(['duration_sec' => 5]);

        $start = $this->actingAs($user)->postJson("/api/ptc/{$ad->id}/start")->json();
        // No heartbeats sent at all.
        PtcView::where('id', $start['view_id'])->update([
            'started_at' => Carbon::now()->subSeconds($ad->duration_sec + 2),
        ]);

        [$challenge] = $this->seedChallenge();
        $challenge->update(['status' => 'verified']);

        $response = $this->actingAs($user)->postJson("/api/ptc/{$start['view_id']}/complete", [
            'epoch_token' => $start['epoch_token'],
            'captcha_challenge_id' => $challenge->challenge_id,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'heartbeat_deficit']);
        $this->assertSame(0, (int) $user->fresh()->balance_sat);
        $this->assertSame('rejected', PtcView::find($start['view_id'])->status);
    }

    public function test_complete_rejects_when_elapsed_is_too_fast(): void
    {
        $user = User::factory()->create(['balance_sat' => 0]);
        $ad = $this->seedAd(['duration_sec' => 30]);

        $start = $this->actingAs($user)->postJson("/api/ptc/{$ad->id}/start")->json();
        for ($i = 0; $i < $start['heartbeats_expected']; $i++) {
            $this->actingAs($user)->postJson("/api/ptc/{$start['view_id']}/heartbeat", [
                'epoch_token' => $start['epoch_token'],
                'beacon_at_ms' => now()->valueOf() + $i * 100,
            ])->assertOk();
        }
        // started_at left untouched (just now) so elapsed ≈ 0 << duration_sec.

        [$challenge] = $this->seedChallenge();
        $challenge->update(['status' => 'verified']);

        $response = $this->actingAs($user)->postJson("/api/ptc/{$start['view_id']}/complete", [
            'epoch_token' => $start['epoch_token'],
            'captcha_challenge_id' => $challenge->challenge_id,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'too_fast']);
        $this->assertSame(0, (int) $user->fresh()->balance_sat);
    }

    public function test_user_cannot_view_their_own_ad(): void
    {
        $user = User::factory()->create();
        $ad = $this->seedAd(['user_id' => $user->id, 'source' => 'user']);

        // servableAdsQuery excludes the viewer's own ads, so /start should 404.
        $response = $this->actingAs($user)->postJson("/api/ptc/{$ad->id}/start");
        $response->assertNotFound();
    }

    private function seedAd(array $overrides = []): PtcAd
    {
        return PtcAd::create(array_merge([
            'user_id' => null,
            'source' => 'mock',
            'external_id' => 'test-'.uniqid(),
            'title' => 'Test Ad',
            'description' => 'Seeded for ViewerFlowTest',
            'target_url' => 'https://example.com/ad-target',
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

    /** @return array{0: CaptchaChallenge, 1: array<int, array{x: float, y: float, t: float}>} */
    private function seedChallenge(): array
    {
        $shape = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 40, 2, 8000, 60);
        $issuedAt = Carbon::now()->subSeconds(3);
        $challenge = CaptchaChallenge::create([
            'challenge_id' => 'cc_test_'.uniqid(),
            'user_id' => null,
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
        return [$challenge, $shape];
    }

    /**
     * @param  array<int, array{x: float, y: float, t: float}>  $shape
     * @return array<int, array{x: float, y: float, t: float, pressure: float}>
     */
    private function humanLikeTrace(array $shape): array
    {
        $points = [];
        $tCursor = 0.0;
        for ($i = 0; $i < 80; $i++) {
            $u = $i / 79;
            $idx = (int) round($u * (count($shape) - 1));
            $tCursor += 16.0 + (mt_rand(-100, 100) / 100.0) * 4.0;
            $points[] = [
                'x' => $shape[$idx]['x'] + (mt_rand(-100, 100) / 100.0) * 1.5,
                'y' => $shape[$idx]['y'] + (mt_rand(-100, 100) / 100.0) * 1.5,
                't' => round($tCursor, 2),
                'pressure' => 0.4 + (mt_rand(0, 60) / 100.0),
            ];
        }
        for ($k = 0; $k < 20; $k++) {
            $tCursor += 15.5;
            $points[] = [
                'x' => $shape[count($shape) - 1]['x'] + (mt_rand(-50, 50) / 100.0),
                'y' => $shape[count($shape) - 1]['y'] + (mt_rand(-50, 50) / 100.0),
                't' => round($tCursor, 2),
                'pressure' => 0.5,
            ];
        }
        return $points;
    }
}
