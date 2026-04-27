<?php

namespace Tests\Feature\Auth;

use App\Captcha\TrajectoryTraceProvider;
use App\Mail\WelcomeEmail;
use App\Models\CaptchaChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Locks down the public /register flow:
 *   - synthetic uniform-Δt traces (the canonical headless-Playwright pattern)
 *     get rejected by the captcha before a User is ever created
 *   - a humanoid trace creates the User and queues the welcome email
 */
class RegisterFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
        Mail::fake();
    }

    public function test_synthetic_uniform_dt_trace_is_rejected_at_register(): void
    {
        [$challenge, $shape] = $this->seedChallenge();

        // Bot-like trace — perfectly even Δt, no pressure, identical-shape replay.
        $points = [];
        for ($i = 0; $i < 80; $i++) {
            $u = $i / 79;
            $idx = (int) round($u * (count($shape) - 1));
            $points[] = ['x' => $shape[$idx]['x'], 'y' => $shape[$idx]['y'], 't' => round(16.0 * $i, 2), 'pressure' => 0];
        }
        // Add the dwell, still uniform.
        $tCursor = 16.0 * 79;
        for ($k = 0; $k < 18; $k++) {
            $tCursor += 16.0;
            $points[] = ['x' => $shape[count($shape) - 1]['x'], 'y' => $shape[count($shape) - 1]['y'], 't' => round($tCursor, 2), 'pressure' => 0];
        }

        $response = $this->postJson('/register', $this->basePayload() + [
            'captcha_challenge_id' => $challenge->challenge_id,
            'captcha_points' => json_encode($points),
        ]);

        $response->assertStatus(422);
        $response->assertJson(['status' => 'error']);
        $this->assertDatabaseMissing('users', ['email' => 'newhuman@example.com']);
        Mail::assertNothingQueued();
    }

    public function test_human_like_trace_creates_user_and_queues_welcome_email(): void
    {
        [$challenge, $shape] = $this->seedChallenge();

        // Humanoid trace — slight Δt jitter, sub-pixel positional jitter, dwell.
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

        // The captcha was issued without a fingerprint hash so the verifier
        // skips the binding check — that mirrors what the JS does on the form.
        $response = $this->postJson('/register', $this->basePayload() + [
            'captcha_challenge_id' => $challenge->challenge_id,
            'captcha_points' => json_encode($points),
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'redirect' => route('dashboard')]);
        $this->assertDatabaseHas('users', ['email' => 'newhuman@example.com', 'username' => 'newhuman']);
        Mail::assertQueued(WelcomeEmail::class, fn ($mail) => $mail->user->email === 'newhuman@example.com');
    }

    /** @return array{0: CaptchaChallenge, 1: array<int, array{x: float, y: float, t: float}>} */
    private function seedChallenge(): array
    {
        $shape = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 40, 2, 8000, 60);
        $issuedAt = Carbon::now()->subSeconds(3); // simulate ~3 s elapsed
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
            'expires_at' => $issuedAt->copy()->addSeconds(30),
        ]);

        return [$challenge, $shape];
    }

    /** @return array<string, mixed> */
    private function basePayload(): array
    {
        return [
            'username' => 'newhuman',
            'email' => 'newhuman@example.com',
            'password' => 'supersecret1',
            'password_confirmation' => 'supersecret1',
            'agree' => '1',
        ];
    }
}
