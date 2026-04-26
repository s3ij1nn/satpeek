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
        $provider = new TrajectoryTraceProvider();
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
        $provider = new TrajectoryTraceProvider();
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
