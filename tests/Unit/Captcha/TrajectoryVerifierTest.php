<?php

namespace Tests\Unit\Captcha;

use App\Captcha\TrajectoryTraceProvider;
use Tests\TestCase;

class TrajectoryVerifierTest extends TestCase
{
    private TrajectoryTraceProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new TrajectoryTraceProvider;
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

    public function test_natural_human_like_trace_passes(): void
    {
        $shape = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 40, 2, 8000, 60);
        $points = $this->humanLikeFollow($shape, jitterMs: 5.0, posJitter: 1.5);

        $result = $this->provider->verify(
            challenge: ['expected_shape' => $shape, 'fingerprint_hash' => null],
            points: $points,
            context: ['solve_ms' => 6500, 'fingerprint_hash' => null]
        );
        $this->assertTrue($result->passed, "rejected with reason: {$result->reason}");
        $this->assertGreaterThan(0.4, $result->confidence);
    }

    public function test_bezier_replay_fails_for_low_jerk_entropy(): void
    {
        $shape = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 40, 2, 8000, 60);
        $points = [];
        // Perfectly smooth replay along the analytical curve, no human jitter.
        $sampleCount = 80;
        for ($i = 0; $i < $sampleCount; $i++) {
            $u = $i / ($sampleCount - 1);
            $tMs = $u * 6000;
            $idx = (int) round($u * (count($shape) - 1));
            $points[] = [
                'x' => $shape[$idx]['x'],
                'y' => $shape[$idx]['y'],
                't' => round(16.67 * $i, 2),
                'pressure' => 0.5,
            ];
        }
        $result = $this->provider->verify(
            challenge: ['expected_shape' => $shape, 'fingerprint_hash' => null],
            points: $points,
            context: ['solve_ms' => 6500, 'fingerprint_hash' => null]
        );
        $this->assertFalse($result->passed, 'bezier replay must fail');
        $this->assertContains($result->reason, ['dt_too_uniform', 'jerk_too_smooth', 'no_completion_dwell']);
    }

    public function test_too_fast_solve_window_rejects(): void
    {
        $shape = TrajectoryTraceProvider::sampleCurve('linear', 30, 120, 280, 120, 0, 1, 6000, 60);
        $points = $this->humanLikeFollow($shape);
        $result = $this->provider->verify(
            challenge: ['expected_shape' => $shape, 'fingerprint_hash' => null],
            points: $points,
            context: ['solve_ms' => 200, 'fingerprint_hash' => null]
        );
        $this->assertFalse($result->passed);
        $this->assertSame('too_fast', $result->reason);
    }

    public function test_too_slow_solve_window_rejects_relay(): void
    {
        $shape = TrajectoryTraceProvider::sampleCurve('linear', 30, 120, 280, 120, 0, 1, 6000, 60);
        $points = $this->humanLikeFollow($shape);
        $result = $this->provider->verify(
            challenge: ['expected_shape' => $shape, 'fingerprint_hash' => null],
            points: $points,
            context: ['solve_ms' => 30000, 'fingerprint_hash' => null]
        );
        $this->assertFalse($result->passed);
        $this->assertSame('too_slow_relay', $result->reason);
    }

    public function test_fingerprint_mismatch_rejects(): void
    {
        $shape = TrajectoryTraceProvider::sampleCurve('linear', 30, 120, 280, 120, 0, 1, 6000, 60);
        $points = $this->humanLikeFollow($shape);
        $result = $this->provider->verify(
            challenge: ['expected_shape' => $shape, 'fingerprint_hash' => 'fp-issue'],
            points: $points,
            context: ['solve_ms' => 5000, 'fingerprint_hash' => 'fp-different']
        );
        $this->assertFalse($result->passed);
        $this->assertSame('fingerprint_mismatch', $result->reason);
    }

    public function test_static_screenshot_relay_only_returns_one_point(): void
    {
        // A 2captcha worker can only return a single (x, y) for a static
        // screenshot. The min_points threshold rejects this immediately.
        $shape = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 40, 2, 8000, 60);
        $result = $this->provider->verify(
            challenge: ['expected_shape' => $shape, 'fingerprint_hash' => null],
            points: [['x' => 280, 'y' => 120, 't' => 5000, 'pressure' => 0]],
            context: ['solve_ms' => 12000, 'fingerprint_hash' => null]
        );
        $this->assertFalse($result->passed);
        $this->assertSame('too_few_points', $result->reason);
    }

    /**
     * Locks the curve roster so adding / removing a flavour fails CI loud.
     * We don't pin the exact list to make refactors painless, just the
     * minimum cardinality and that every name resolves to a non-trivial
     * shape (more than one distinct y across the sweep).
     */
    public function test_curve_roster_has_minimum_six_distinct_shapes(): void
    {
        // Reflect to read the private constant.
        $r = new \ReflectionClass(TrajectoryTraceProvider::class);
        $names = $r->getConstant('CURVES');
        $this->assertIsArray($names);
        $this->assertGreaterThanOrEqual(6, count($names), 'curve diversity is a defence — keep at least six flavours');

        foreach ($names as $name) {
            $shape = TrajectoryTraceProvider::sampleCurve(
                $name, 30, 120, 280, 120, 40, 2, 8000, 60
            );
            $this->assertCount(60, $shape, "curve {$name} must produce the requested sample count");
            $ys = array_map(fn ($p) => (float) $p['y'], $shape);
            // Linear is the deliberate flat exception (1 distinct y).
            $minDistinct = $name === 'linear' ? 1 : 5;
            $this->assertGreaterThanOrEqual(
                $minDistinct,
                count(array_unique($ys)),
                "curve {$name} should produce a meaningfully varied y-trace, got ".count(array_unique($ys)).' distinct values'
            );
        }
    }

    /**
     * Human-like follow against each curve flavour must pass the verifier.
     * Catches the regression where a new curve introduces a singularity
     * or amplitude that the existing tolerance / jerk-entropy thresholds
     * can't handle.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('curveProvider')]
    public function test_human_like_trace_passes_for_every_curve(string $curve): void
    {
        $shape = TrajectoryTraceProvider::sampleCurve($curve, 30, 120, 280, 120, 40, 2, 8000, 60);
        $points = $this->humanLikeFollow($shape, jitterMs: 5.0, posJitter: 1.5);

        $result = $this->provider->verify(
            challenge: ['expected_shape' => $shape, 'fingerprint_hash' => null],
            points: $points,
            context: ['solve_ms' => 6500, 'fingerprint_hash' => null]
        );
        $this->assertTrue(
            $result->passed,
            "curve `{$curve}` rejected a human-like follow with reason: {$result->reason}"
        );
    }

    /** @return array<string, array{0: string}> */
    public static function curveProvider(): array
    {
        return [
            'linear' => ['linear'],
            'sine' => ['sine'],
            'lissajous' => ['lissajous'],
            'damped_sine' => ['damped_sine'],
            'growing_sine' => ['growing_sine'],
            'triangle' => ['triangle'],
        ];
    }

    /**
     * Synthesise a "humanish" trace: follow the canonical curve with sub-pixel
     * jitter, ~16ms intervals, randomised pressure, and a 250ms dwell at end.
     *
     * @param  array<int, array{x: float, y: float, t: float}>  $shape
     * @return array<int, array{x: float, y: float, t: float, pressure: float}>
     */
    private function humanLikeFollow(array $shape, float $jitterMs = 4.5, float $posJitter = 2.0): array
    {
        $points = [];
        $tCursor = 0.0;
        $sampleCount = max(60, count($shape));
        for ($i = 0; $i < $sampleCount; $i++) {
            $u = $i / ($sampleCount - 1);
            $idx = (int) round($u * (count($shape) - 1));
            $tCursor += 16.67 + (mt_rand(-100, 100) / 100.0) * $jitterMs;
            $points[] = [
                'x' => $shape[$idx]['x'] + (mt_rand(-100, 100) / 100.0) * $posJitter,
                'y' => $shape[$idx]['y'] + (mt_rand(-100, 100) / 100.0) * $posJitter,
                't' => round($tCursor, 2),
                'pressure' => 0.4 + (mt_rand(0, 60) / 100.0),
            ];
        }
        // Add 280ms dwell at the end (≥ min_completion_dwell_ms 150).
        $last = end($points);
        for ($i = 1; $i <= 18; $i++) {
            $tCursor += 15.5;
            $points[] = [
                'x' => $last['x'] + (mt_rand(-50, 50) / 100.0),
                'y' => $last['y'] + (mt_rand(-50, 50) / 100.0),
                't' => round($tCursor, 2),
                'pressure' => 0.5,
            ];
        }

        return $points;
    }
}
