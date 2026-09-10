<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SolvesTrajectoryCaptcha;
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
    use SolvesTrajectoryCaptcha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applyCaptchaTestConfig();
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
}
