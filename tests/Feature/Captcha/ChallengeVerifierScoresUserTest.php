<?php

declare(strict_types=1);

namespace Tests\Feature\Captcha;

use App\Captcha\ChallengeVerifier;
use App\Captcha\TrajectoryTraceProvider;
use App\Models\BotScore;
use App\Models\CaptchaChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks the contract that a successful captcha verify re-evaluates the
 * user's bot score. Without this hook, the captcha-driven signals
 * (response_time, trajectory_entropy, failure_rate,
 * fingerprint_consistency) only update at login / register — a bot
 * that grinds PTC views never gets tier-bumped from its own captcha
 * behaviour.
 */
class ChallengeVerifierScoresUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Captcha tolerances matching the running site so the trace
        // verifier accepts the seeded human-like trace.
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

    public function test_passing_captcha_writes_bot_score_row_for_owning_user(): void
    {
        $user = User::factory()->create();
        $challenge = $this->seedChallenge($user);
        $points = $this->humanLikeTrace($challenge->expected_shape);

        $this->assertNull(BotScore::query()->where('user_id', $user->id)->first());

        $verifier = $this->app->make(ChallengeVerifier::class);
        $result = $verifier->verify($this->fakeRequest(), $challenge->challenge_id, $points);

        $this->assertTrue($result->passed, 'Trace must verify; otherwise the score-eval hook can never fire');
        $this->assertNotNull(
            BotScore::query()->where('user_id', $user->id)->first(),
            'A successful captcha verify must trigger ScoreEngine for the owning user'
        );
    }

    public function test_failing_captcha_does_not_score_user(): void
    {
        $user = User::factory()->create();
        $challenge = $this->seedChallenge($user);
        // Submit garbage points — verifier rejects on shape_mismatch.
        $bogus = [
            ['x' => 9999, 'y' => 9999, 't' => 0],
            ['x' => 9999, 'y' => 9999, 't' => 16],
        ];

        $verifier = $this->app->make(ChallengeVerifier::class);
        $result = $verifier->verify($this->fakeRequest(), $challenge->challenge_id, $bogus);

        $this->assertFalse($result->passed);
        $this->assertNull(
            BotScore::query()->where('user_id', $user->id)->first(),
            'A rejected captcha must not bypass the throttle / write a score row'
        );
    }

    public function test_anonymous_challenge_does_not_attempt_to_score(): void
    {
        // user_id null = pre-auth captcha (the login form, before we know
        // who the user is). The verifier must finish cleanly without
        // attempting a score eval on a null user.
        $challenge = $this->seedChallenge(user: null);
        $points = $this->humanLikeTrace($challenge->expected_shape);

        $verifier = $this->app->make(ChallengeVerifier::class);
        $result = $verifier->verify($this->fakeRequest(), $challenge->challenge_id, $points);

        $this->assertTrue($result->passed);
        $this->assertSame(0, BotScore::query()->count(), 'No score should be written when challenge has no user');
    }

    private function seedChallenge(?User $user): CaptchaChallenge
    {
        $shape = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 40, 2, 8000, 60);
        // Backdate issued_at so the verifier sees solve_ms > min_solve_ms
        // (800 ms). 9 s is comfortably inside the 60 s upper bound too.
        $issued = Carbon::now()->subSeconds(9);

        return CaptchaChallenge::create([
            'challenge_id' => 'cha_'.bin2hex(random_bytes(8)),
            'user_id' => $user?->id,
            'provider' => 'trajectory_trace',
            'seed' => bin2hex(random_bytes(16)),
            'expected_shape' => $shape,
            'fingerprint_hash' => null,
            'status' => 'issued',
            'issued_at' => $issued,
            'expires_at' => $issued->copy()->addSeconds(60),
            'meta' => ['curve' => 'sine'],
        ]);
    }

    /** @return array<int, array{x: float, y: float, t: float, pressure: float}> */
    private function humanLikeTrace(array $shape): array
    {
        srand(0xC0FFEE);
        $points = [];
        $tCursor = 0.0;
        for ($i = 0; $i < 80; $i++) {
            $u = $i / 79;
            $idx = (int) round($u * (count($shape) - 1));
            $tCursor += 16.0 + (rand(-100, 100) / 100.0) * 4.0;
            $points[] = [
                'x' => $shape[$idx]['x'] + (rand(-100, 100) / 100.0),
                'y' => $shape[$idx]['y'] + (rand(-100, 100) / 100.0),
                't' => round($tCursor, 2),
                'pressure' => 0.5 + (rand(-30, 30) / 100.0),
            ];
        }
        // dwell at goal
        for ($i = 0; $i < 20; $i++) {
            $tCursor += 15.5;
            $points[] = [
                'x' => $shape[count($shape) - 1]['x'],
                'y' => $shape[count($shape) - 1]['y'],
                't' => round($tCursor, 2),
                'pressure' => 0.5,
            ];
        }

        return $points;
    }

    private function fakeRequest(): Request
    {
        return Request::create('/captcha/verify', 'POST', server: ['REMOTE_ADDR' => '203.0.113.10']);
    }
}
