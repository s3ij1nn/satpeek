<?php

namespace Tests\BotSimulation;

use App\Captcha\TrajectoryTraceProvider;
use Tests\TestCase;

/**
 * Simulates a Playwright-driven attacker that:
 *   - submits trajectory points using `page.mouse.move` (no pressure, perfect Δt)
 *   - matches the canonical curve closely (defeats simple shape check)
 *
 * The verifier MUST still reject this because Δt jitter / jerk entropy /
 * pressure variance cannot be faithfully replicated through CDP-driven
 * synthetic input.
 */
class PlaywrightHeadlessTest extends TestCase
{
    public function test_synthetic_uniform_dt_trajectory_is_rejected(): void
    {
        $this->seedConfig();
        $provider = new TrajectoryTraceProvider;
        $shape = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 40, 2, 8000, 60);

        $points = [];
        // Playwright generally fires events at a fixed rate when scripted.
        $sampleCount = 100;
        for ($i = 0; $i < $sampleCount; $i++) {
            $u = $i / ($sampleCount - 1);
            $idx = (int) round($u * (count($shape) - 1));
            $points[] = [
                'x' => $shape[$idx]['x'],
                'y' => $shape[$idx]['y'],
                't' => round(16.0 * $i, 2),
                'pressure' => 0,
            ];
        }
        // The bot WILL include some dwell at the goal because it knows
        // about that requirement — but Δt remains perfect.
        $tCursor = 16.0 * ($sampleCount - 1);
        for ($k = 0; $k < 18; $k++) {
            $tCursor += 16.0;
            $points[] = [
                'x' => $shape[count($shape) - 1]['x'],
                'y' => $shape[count($shape) - 1]['y'],
                't' => round($tCursor, 2),
                'pressure' => 0,
            ];
        }

        $result = $provider->verify(
            challenge: ['expected_shape' => $shape, 'fingerprint_hash' => null],
            points: $points,
            context: ['solve_ms' => 6000, 'fingerprint_hash' => null]
        );

        $this->assertFalse($result->passed, 'synthetic uniform-dt bot must be rejected');
        $this->assertContains($result->reason, ['dt_too_uniform', 'jerk_too_smooth']);
    }

    public function test_2captcha_relay_response_window_rejects(): void
    {
        $this->seedConfig();
        $provider = new TrajectoryTraceProvider;
        $shape = TrajectoryTraceProvider::sampleCurve('linear', 30, 120, 280, 120, 0, 1, 6000, 60);
        // Even if the relay returned a perfectly valid trajectory, the round
        // trip through 2captcha takes 25-45s, blowing the upper time bound.
        $points = $this->humanLikeFollow($shape);
        $result = $provider->verify(
            challenge: ['expected_shape' => $shape, 'fingerprint_hash' => null],
            points: $points,
            context: ['solve_ms' => 35000, 'fingerprint_hash' => null]
        );
        $this->assertFalse($result->passed);
        $this->assertSame('too_slow_relay', $result->reason);
    }

    /**
     * The most realistic attack: bot harvests a known-good trajectory from a
     * solved challenge (manual capture, or an early successful CDP run) and
     * replays the EXACT point stream against subsequent challenges.
     *
     * Each new challenge issues a fresh seed → fresh curve / amplitude /
     * frequency. The recorded points map to the OLD curve, so when verified
     * against the NEW expected_shape they hit shape_mismatch immediately —
     * regardless of how plausible the inner timing / jerk signals look.
     *
     * This is the property the seed-per-challenge invariant exists to
     * preserve. A regression here (e.g. caching a global shape) is the
     * single most dangerous regression we can ship.
     */
    public function test_recorded_trace_replayed_against_fresh_challenge_fails_shape(): void
    {
        $this->seedConfig();
        $provider = new TrajectoryTraceProvider;

        // Challenge A — what the attacker recorded from a previous solve.
        $shapeRecorded = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 60, 2, 7000, 60);
        $points = $this->humanLikeFollow($shapeRecorded);

        // Challenge B — the new fresh-seed shape the bot is now trying to
        // pass. Different curve family AND different amplitude / frequency,
        // mirroring what `ChallengeBuilder::issue()` would produce on every
        // request.
        $shapeFresh = TrajectoryTraceProvider::sampleCurve('lissajous', 30, 120, 280, 120, 80, 3, 7000, 60);

        $result = $provider->verify(
            challenge: ['expected_shape' => $shapeFresh, 'fingerprint_hash' => null],
            points: $points,
            context: ['solve_ms' => 7000, 'fingerprint_hash' => null]
        );

        $this->assertFalse($result->passed, 'replayed-trace bot must be rejected against fresh challenge');
        $this->assertSame('shape_mismatch', $result->reason);
    }

    /**
     * Naive 2captcha-class bot that solves the curve correctly but doesn't
     * know about the goal-dwell requirement. The drag finishes the moment
     * the cursor hits the end coordinates and the bot submits — no settle.
     *
     * Most relay-script attackers we've seen treat captcha solving as
     * "click here, drag there, submit" without modeling the post-arrival
     * settle window. The completion-dwell gate catches this even when
     * timing / shape / jerk all look fine.
     */
    public function test_no_post_arrival_dwell_is_rejected(): void
    {
        $this->seedConfig();
        $provider = new TrajectoryTraceProvider;
        $shape = TrajectoryTraceProvider::sampleCurve('linear', 30, 120, 280, 120, 0, 1, 6000, 80);

        // Deterministic position + dt jitter so the test is not flaky.
        srand(0x4D003);
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
                'pressure' => 0,
            ];
        }
        // NOTE: no dwell points appended — bot submits the moment the cursor
        // reaches the goal. This is the exact pattern naive scripted clicks
        // produce.

        $result = $provider->verify(
            challenge: ['expected_shape' => $shape, 'fingerprint_hash' => null],
            points: $points,
            context: ['solve_ms' => (int) $tCursor, 'fingerprint_hash' => null]
        );

        $this->assertFalse($result->passed, 'bot without post-arrival dwell must be rejected');
        $this->assertSame('no_completion_dwell', $result->reason);
    }

    /**
     * "Solve too fast" guard. A 2captcha-style relay, or a script that
     * front-runs the moving target, can submit before the human-plausible
     * lower bound. Lock the gate so a future config bump (e.g. raising the
     * window for accessibility) doesn't accidentally drop the lower bound.
     */
    public function test_sub_minimum_solve_time_is_rejected_with_too_fast_reason(): void
    {
        $this->seedConfig();
        $provider = new TrajectoryTraceProvider;
        $shape = TrajectoryTraceProvider::sampleCurve('linear', 30, 120, 280, 120, 0, 1, 6000, 60);
        $points = $this->humanLikeFollow($shape);

        $result = $provider->verify(
            challenge: ['expected_shape' => $shape, 'fingerprint_hash' => null],
            points: $points,
            // 200 ms — well under the 800 ms minimum; a real human can't
            // even start a drag in this window.
            context: ['solve_ms' => 200, 'fingerprint_hash' => null]
        );

        $this->assertFalse($result->passed);
        $this->assertSame('too_fast', $result->reason);
    }

    private function seedConfig(): void
    {
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
    }

    private function humanLikeFollow(array $shape): array
    {
        $points = [];
        $tCursor = 0.0;
        for ($i = 0; $i < 80; $i++) {
            $u = $i / 79;
            $idx = (int) round($u * (count($shape) - 1));
            $tCursor += 16.0 + (mt_rand(-100, 100) / 100.0) * 4.0;
            $points[] = [
                'x' => $shape[$idx]['x'] + (mt_rand(-100, 100) / 100.0),
                'y' => $shape[$idx]['y'] + (mt_rand(-100, 100) / 100.0),
                't' => round($tCursor, 2),
                'pressure' => 0.5 + (mt_rand(-30, 30) / 100.0),
            ];
        }
        for ($i = 1; $i <= 20; $i++) {
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
}
