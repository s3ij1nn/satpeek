<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Captcha\TrajectoryTraceProvider;
use App\Models\CaptchaChallenge;
use Illuminate\Support\Carbon;

/**
 * Shared fixtures for the tests that drive a real captcha solve end to end.
 *
 * Every one of them needs the same three things: deterministic verifier
 * thresholds, an `issued` challenge row with a known expected shape, and a
 * trace that clears those thresholds. The helpers lived in triplicate across
 * RegisterFlowTest / VerificationResendTest / AdminLoginCaptchaTest before
 * this trait — which meant a tuning change to `config('satpeek.captcha')`
 * had to be mirrored by hand in every copy.
 *
 * The *rejection* traces stay inline in each test: they're the actual subject
 * under test there, and each one exercises a slightly different bot shape.
 */
trait SolvesTrajectoryCaptcha
{
    /**
     * Pin the verifier thresholds so the synthesised traces below are
     * judged against fixed numbers rather than whatever `config/satpeek.php`
     * currently ships. Call from `setUp()`.
     */
    protected function applyCaptchaTestConfig(): void
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

    /**
     * Insert an `issued` challenge dated ~3 s ago, which lands inside the
     * [800 ms, 25 s] solve window the config above sets.
     *
     * `fingerprint_hash` is left null so the verifier skips the binding
     * check — that mirrors the JS, which sends `X-SP-Fingerprint` on issue
     * but the tests here post without one.
     *
     * @return array{0: CaptchaChallenge, 1: array<int, array{x: float, y: float, t: float}>}
     */
    protected function seedChallenge(?int $userId = null): array
    {
        $shape = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 40, 2, 8000, 60);
        $issuedAt = Carbon::now()->subSeconds(3);
        $challenge = CaptchaChallenge::create([
            'challenge_id' => 'cc_test_'.uniqid('', true),
            'user_id' => $userId,
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
     * A trace that passes: Δt jitter above `min_dt_jitter_ratio`, sub-pixel
     * positional wobble inside `shape_tolerance_px`, varying pressure, and a
     * dwell at the goal long enough for `min_completion_dwell_ms`.
     *
     * @param  array<int, array{x: float, y: float, t: float}>  $shape
     * @return array<int, array{x: float, y: float, t: float, pressure: float}>
     */
    protected function humanoidTrace(array $shape): array
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
