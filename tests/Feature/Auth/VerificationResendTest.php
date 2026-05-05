<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Captcha\TrajectoryTraceProvider;
use App\Models\CaptchaChallenge;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pins the captcha + per-user rate-limit gates on
 * `POST /email/verification-notification`.
 *
 * Pre-fix the route was protected only by `throttle:6,1` (per-IP), which
 * a botnet trivially bypasses by rotating IPs while reusing one stolen
 * session — the inbox-bombing pattern. Two layers go in front of the
 * resend endpoint now:
 *
 *   - per-user named limiter `verification-send` (1/min, 6/hr)
 *   - trajectory-captcha solve required on every submit
 *
 * The captcha is the same widget the register and login forms use, so
 * the JS surface area stays unchanged. These tests pin the server-side
 * contract; the JS is exercised by Playwright in the bot-simulation suite.
 */
class VerificationResendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Same captcha tuning as RegisterFlowTest so the humanoid trace
        // we synthesise here passes the same shape/jerk thresholds.
        config()->set('satpeek.captcha', [
            'ttl_ms' => 30000,
            'min_solve_ms' => 800,
            'max_solve_ms' => 25000,
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
        Notification::fake();
    }

    public function test_resend_without_captcha_is_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $response = $this->actingAs($user)->post(route('verification.send'));

        $response->assertSessionHasErrors(['captcha_challenge_id', 'captcha_points']);
        Notification::assertNothingSent();
    }

    public function test_resend_with_synthetic_uniform_dt_trace_is_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        [$challenge, $shape] = $this->seedChallenge();

        // Bot-like: perfectly uniform Δt, identical-shape replay.
        $points = [];
        for ($i = 0; $i < 80; $i++) {
            $idx = (int) round(($i / 79) * (count($shape) - 1));
            $points[] = ['x' => $shape[$idx]['x'], 'y' => $shape[$idx]['y'], 't' => 16.0 * $i, 'pressure' => 0];
        }

        $response = $this->actingAs($user)->post(route('verification.send'), [
            'captcha_challenge_id' => $challenge->challenge_id,
            'captcha_points' => json_encode($points),
        ]);

        $response->assertSessionHasErrors('captcha');
        Notification::assertNothingSent();
    }

    public function test_resend_with_humanoid_trace_sends_verification_email(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        [$challenge, $shape] = $this->seedChallenge();
        $points = $this->humanoidTrace($shape);

        $response = $this->actingAs($user)->post(route('verification.send'), [
            'captcha_challenge_id' => $challenge->challenge_id,
            'captcha_points' => json_encode($points),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_per_user_rate_limit_blocks_immediate_second_resend(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        [$challenge1, $shape] = $this->seedChallenge();
        [$challenge2] = $this->seedChallenge();

        // First resend succeeds.
        $this->actingAs($user)->post(route('verification.send'), [
            'captcha_challenge_id' => $challenge1->challenge_id,
            'captcha_points' => json_encode($this->humanoidTrace($shape)),
        ])->assertRedirect();

        Notification::assertSentTimes(VerifyEmail::class, 1);

        // Second resend within the same minute hits the named limiter
        // and returns 429. No second email queued.
        $second = $this->actingAs($user)->post(route('verification.send'), [
            'captcha_challenge_id' => $challenge2->challenge_id,
            'captcha_points' => json_encode($this->humanoidTrace($shape)),
        ]);
        $second->assertStatus(429);
        Notification::assertSentTimes(VerifyEmail::class, 1);
    }

    public function test_already_verified_user_is_redirected_to_dashboard(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        // No captcha submission needed — controller short-circuits before
        // the validate() call when the user is already verified.
        $response = $this->actingAs($user)->post(route('verification.send'));

        $response->assertRedirect(route('dashboard'));
        Notification::assertNothingSent();
    }

    /** @return array{0: CaptchaChallenge, 1: array<int, array{x: float, y: float, t: float}>} */
    private function seedChallenge(): array
    {
        $shape = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 40, 2, 8000, 60);
        $issuedAt = Carbon::now()->subSeconds(3);
        $challenge = CaptchaChallenge::create([
            'challenge_id' => 'cc_test_'.uniqid('', true),
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
            'expires_at' => $issuedAt->copy()->addSeconds(30),
        ]);

        return [$challenge, $shape];
    }

    /**
     * Humanoid trace pattern lifted from RegisterFlowTest — Δt jitter,
     * sub-pixel positional jitter, and a dwell at the goal so the
     * verifier's shape + jerk-entropy + dwell checks all pass.
     *
     * @param  array<int, array{x: float, y: float, t: float}>  $shape
     * @return array<int, array{x: float, y: float, t: float, pressure: float}>
     */
    private function humanoidTrace(array $shape): array
    {
        $points = [];
        $tCursor = 0.0;
        for ($i = 0; $i < 80; $i++) {
            $idx = (int) round(($i / 79) * (count($shape) - 1));
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
