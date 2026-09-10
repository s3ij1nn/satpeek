<?php

namespace Tests\Feature\Auth;

use App\Mail\WelcomeEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SolvesTrajectoryCaptcha;
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
    use SolvesTrajectoryCaptcha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applyCaptchaTestConfig();
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

        $points = $this->humanoidTrace($shape);

        $response = $this->postJson('/register', $this->basePayload() + [
            'captcha_challenge_id' => $challenge->challenge_id,
            'captcha_points' => json_encode($points),
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'redirect' => route('dashboard')]);
        $this->assertDatabaseHas('users', ['email' => 'newhuman@example.com', 'username' => 'newhuman']);
        Mail::assertQueued(WelcomeEmail::class, fn ($mail) => $mail->user->email === 'newhuman@example.com');
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
