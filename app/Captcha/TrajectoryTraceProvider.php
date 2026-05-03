<?php

namespace App\Captcha;

use App\Captcha\Contracts\CaptchaProvider;
use App\Captcha\Contracts\VerificationResult;
use Illuminate\Support\Str;

/**
 * Trajectory-trace captcha.
 *
 * Asks the human to drag a token along a server-generated parametric curve
 * (linear / sine / lissajous) that is reproducible only via the per-issue
 * seed. The "answer" is a continuous trajectory of (x, y, t, pressure)
 * samples — not a single coordinate. This breaks every static-image relay
 * service (2captcha, Anti-Captcha, OpenRouter VLM) by construction:
 *
 *   1. Cannot be reduced to one PNG (the target moves over time).
 *   2. The answer surface is N×4 dimensional, not a point.
 *   3. Server validates jitter / jerk entropy / completion dwell — Bezier
 *      one-shot replay fails even if the shape is correct.
 *   4. Issued seed is bound to the original session+TLS fingerprint;
 *      relay traffic from a different network presents mismatched fp.
 *   5. Min/max solve time window rejects scripts (< 800ms) and human
 *      relay services (> 25 s).
 */
class TrajectoryTraceProvider implements CaptchaProvider
{
    private const CANVAS_W = 320;

    private const CANVAS_H = 240;

    private const SHAPE_SAMPLES = 60;

    /**
     * The set of canonical curves a single challenge can sample.
     *
     * Curve diversity is a first-class defence: a bot that fine-tunes
     * a Bezier replay for `sine` sees only ~1/N of issued challenges;
     * adding a curve flavour cuts the per-flavour replay match rate
     * proportionally without raising any user-facing difficulty knob.
     *
     * - `linear`        baseline diagonal
     * - `sine`          symmetric sin envelope
     * - `lissajous`     sin × cos modulation (variable amplitude)
     * - `damped_sine`   amplitude decays with u — strong start, soft end
     * - `growing_sine`  amplitude grows with u — soft start, strong end
     * - `triangle`      sharp triangle wave, defeats smooth-only Bezier
     */
    private const CURVES = ['linear', 'sine', 'lissajous', 'damped_sine', 'growing_sine', 'triangle'];

    public function name(): string
    {
        return 'trajectory_trace';
    }

    public function issue(string $sessionId, ?int $userId, array $viewport, int $difficulty = 1): array
    {
        $seed = bin2hex(random_bytes(16));
        $rng = self::seededRng($seed);

        // Scale amplitude / frequency / duration with difficulty so a user
        // already flagged as suspect or likely_bot faces a curve that's
        // harder for a bezier-replay or relay-script bot to follow:
        //   1 (trust)      → defaults
        //   2 (suspect)    → 1.5× amplitude, +1 frequency
        //   3 (likely_bot) → 2.0× amplitude, +2 frequency, +1 s duration
        // The score engine's likely_bot tier blocks PTC entirely, so
        // difficulty=3 mainly hardens login + register captchas for a
        // user whose tier moved between captcha issue and re-attempt.
        $difficulty = max(1, min(3, $difficulty));
        $scale = 1.0 + 0.5 * ($difficulty - 1);

        $curve = self::CURVES[$rng() % count(self::CURVES)];
        $startX = 30 + ($rng() % 60);
        $startY = 60 + ($rng() % 120);
        $endX = self::CANVAS_W - 30 - ($rng() % 60);
        $endY = 60 + ($rng() % 120);
        $amplitude = (int) round((30 + ($rng() % 60)) * $scale);
        $frequency = (1 + ($rng() % 3)) + ($difficulty - 1);
        $durationMs = (6000 + ($rng() % 4000)) + ($difficulty - 1) * 1000;

        $expectedShape = self::sampleCurve(
            $curve,
            $startX,
            $startY,
            $endX,
            $endY,
            $amplitude,
            $frequency,
            $durationMs,
            self::SHAPE_SAMPLES
        );

        return [
            'challengeId' => 'cc_'.Str::lower(Str::random(24)),
            'seed' => $seed,
            'payload' => [
                'canvas' => ['w' => self::CANVAS_W, 'h' => self::CANVAS_H],
                'curve' => $curve,
                'start' => ['x' => $startX, 'y' => $startY],
                'end' => ['x' => $endX, 'y' => $endY],
                'amplitude' => $amplitude,
                'frequency' => $frequency,
                'durationMs' => $durationMs,
                'instruction' => 'Drag the green dot along the moving target into the red goal.',
            ],
            'expectedShape' => $expectedShape,
            'ttlMs' => (int) config('satpeek.captcha.ttl_ms', 30000),
        ];
    }

    public function verify(array $challenge, array $points, array $context): VerificationResult
    {
        $cfg = config('satpeek.captcha');
        $signals = [];

        if (count($points) < $cfg['min_points']) {
            return VerificationResult::fail('too_few_points', $signals);
        }
        if (count($points) > $cfg['max_points']) {
            return VerificationResult::fail('too_many_points', $signals);
        }

        $solveMs = (int) ($context['solve_ms'] ?? -1);
        if ($solveMs < $cfg['min_solve_ms']) {
            return VerificationResult::fail('too_fast', ['solve_ms' => $solveMs]);
        }
        if ($solveMs > $cfg['max_solve_ms']) {
            return VerificationResult::fail('too_slow_relay', ['solve_ms' => $solveMs]);
        }
        $signals['solve_ms'] = $solveMs;

        $shape = $challenge['expected_shape'] ?? [];
        if (! is_array($shape) || count($shape) < 4) {
            return VerificationResult::fail('challenge_corrupt');
        }

        $shapeDistance = self::frechetDistance($shape, $points);
        $signals['shape_distance_px'] = $shapeDistance;
        if ($shapeDistance > $cfg['shape_tolerance_px']) {
            return VerificationResult::fail('shape_mismatch', $signals);
        }

        $deltas = self::sampleIntervals($points);
        $median = self::median($deltas);
        $signals['dt_median_ms'] = $median;

        if ($median < $cfg['expected_dt_median_ms_min'] || $median > $cfg['expected_dt_median_ms_max']) {
            return VerificationResult::fail('dt_median_outlier', $signals);
        }

        $jitter = self::jitterRatio($deltas, $median);
        $signals['dt_jitter_ratio'] = $jitter;
        if ($jitter < $cfg['min_dt_jitter_ratio']) {
            return VerificationResult::fail('dt_too_uniform', $signals);
        }

        $jerkEntropy = self::jerkEntropy($points);
        $signals['jerk_entropy'] = $jerkEntropy;
        $minJerkEntropy = (float) ($cfg['min_jerk_entropy'] ?? 1.2);
        if ($jerkEntropy < $minJerkEntropy) {
            return VerificationResult::fail('jerk_too_smooth', $signals);
        }

        $dwellRadius = (float) ($cfg['completion_dwell_radius_px'] ?? 8.0);
        $dwell = self::completionDwellMs($points, $dwellRadius);
        $signals['completion_dwell_ms'] = $dwell;
        if ($dwell < $cfg['min_completion_dwell_ms']) {
            return VerificationResult::fail('no_completion_dwell', $signals);
        }

        $expectedFp = $challenge['fingerprint_hash'] ?? null;
        $providedFp = $context['fingerprint_hash'] ?? null;
        if ($expectedFp && $providedFp && ! hash_equals($expectedFp, $providedFp)) {
            return VerificationResult::fail('fingerprint_mismatch', $signals);
        }

        $confidence = self::scoreConfidence($shapeDistance, $cfg['shape_tolerance_px'], $jitter, $jerkEntropy);

        return VerificationResult::pass($confidence, $signals);
    }

    /**
     * Deterministic seeded RNG using SHA-256 expansion. PHP's mt_srand is
     * insufficient for cross-platform reproducibility, so we expand the seed
     * into a byte stream and read 4 bytes at a time.
     */
    public static function seededRng(string $seed): \Closure
    {
        $buffer = '';
        $cursor = 0;
        $counter = 0;

        return function () use ($seed, &$buffer, &$cursor, &$counter): int {
            if ($cursor + 4 > strlen($buffer)) {
                $buffer = hash('sha256', $seed.':'.$counter, true);
                $counter++;
                $cursor = 0;
            }
            $chunk = substr($buffer, $cursor, 4);
            $cursor += 4;
            $unpacked = unpack('N', $chunk);

            return $unpacked[1] & 0x7FFFFFFF;
        };
    }

    /**
     * @return array<int, array{x: float, y: float, t: float}>
     */
    public static function sampleCurve(
        string $curve,
        int $startX,
        int $startY,
        int $endX,
        int $endY,
        int $amplitude,
        int $frequency,
        int $durationMs,
        int $samples
    ): array {
        $out = [];
        for ($i = 0; $i < $samples; $i++) {
            $u = $i / max(1, $samples - 1);
            $t = $u * $durationMs;
            $x = $startX + ($endX - $startX) * $u;
            $baseY = $startY + ($endY - $startY) * $u;
            $y = match ($curve) {
                'linear' => $baseY,
                'sine' => $baseY + sin($u * M_PI * 2 * $frequency) * $amplitude,
                'lissajous' => $baseY + sin($u * M_PI * 2 * $frequency) * $amplitude * cos($u * M_PI),
                // Amplitude decays linearly with u — the wobble fades as
                // the user approaches the goal. A bot fitting a uniform
                // sine model gets the early peaks right and overshoots
                // the late ones.
                'damped_sine' => $baseY + sin($u * M_PI * 2 * $frequency) * $amplitude * (1.0 - $u),
                // Inverse envelope — flat at the start, growing wobble
                // as u → 1. Symmetric counterpart to damped_sine; a
                // single bezier-replay model can't cover both at once.
                'growing_sine' => $baseY + sin($u * M_PI * 2 * $frequency) * $amplitude * $u,
                // Triangle wave via the arcsin(sin) identity. Sharp
                // peaks instead of smooth — a Bezier-only replay
                // collapses the corners and trips the shape check.
                'triangle' => $baseY + ($amplitude * 2.0 / M_PI) * asin(sin($u * M_PI * 2 * $frequency)),
                default => $baseY,
            };
            $out[] = ['x' => round($x, 2), 'y' => round($y, 2), 't' => round($t, 1)];
        }

        return $out;
    }

    /**
     * Discrete Frechet distance approximation (DTW-light).
     *
     * @param  array<int, array{x: float|int, y: float|int, t?: mixed}>  $a
     * @param  array<int, array<string, mixed>>  $b
     */
    public static function frechetDistance(array $a, array $b): float
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0 || $m === 0) {
            return INF;
        }

        $dp = array_fill(0, $n, array_fill(0, $m, INF));
        $dp[0][0] = self::pointDistance($a[0], $b[0]);
        for ($i = 1; $i < $n; $i++) {
            $dp[$i][0] = max($dp[$i - 1][0], self::pointDistance($a[$i], $b[0]));
        }
        for ($j = 1; $j < $m; $j++) {
            $dp[0][$j] = max($dp[0][$j - 1], self::pointDistance($a[0], $b[$j]));
        }
        for ($i = 1; $i < $n; $i++) {
            for ($j = 1; $j < $m; $j++) {
                $dp[$i][$j] = max(
                    min($dp[$i - 1][$j], $dp[$i - 1][$j - 1], $dp[$i][$j - 1]),
                    self::pointDistance($a[$i], $b[$j])
                );
            }
        }

        return (float) $dp[$n - 1][$m - 1];
    }

    private static function pointDistance(array $a, array $b): float
    {
        $dx = (float) $a['x'] - (float) $b['x'];
        $dy = (float) $a['y'] - (float) $b['y'];

        return sqrt($dx * $dx + $dy * $dy);
    }

    /**
     * @param  array<int, array<string, mixed>>  $points
     * @return array<int, float>
     */
    public static function sampleIntervals(array $points): array
    {
        $deltas = [];
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $prev = (float) ($points[$i - 1]['t'] ?? 0);
            $cur = (float) ($points[$i]['t'] ?? 0);
            if ($cur > $prev) {
                $deltas[] = $cur - $prev;
            }
        }

        return $deltas;
    }

    /**
     * @param  array<int, float>  $values
     */
    public static function median(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 0
            ? ($values[$mid - 1] + $values[$mid]) / 2.0
            : $values[$mid];
    }

    /**
     * Coefficient-of-variation style jitter ratio = stddev / median.
     *
     * @param  array<int, float>  $deltas
     */
    public static function jitterRatio(array $deltas, float $median): float
    {
        if ($median <= 0 || empty($deltas)) {
            return 0.0;
        }
        $mean = array_sum($deltas) / count($deltas);
        $variance = 0.0;
        foreach ($deltas as $d) {
            $variance += ($d - $mean) ** 2;
        }
        $variance /= count($deltas);

        return sqrt($variance) / $median;
    }

    /**
     * Shannon entropy of normalised jerk magnitude histogram.
     *
     * Synthetic Bezier replay produces near-zero jerk variance → low entropy.
     *
     * @param  array<int, array<string, mixed>>  $points
     */
    public static function jerkEntropy(array $points): float
    {
        $n = count($points);
        if ($n < 4) {
            return 0.0;
        }
        $jerks = [];
        for ($i = 3; $i < $n; $i++) {
            $p0 = $points[$i - 3];
            $p1 = $points[$i - 2];
            $p2 = $points[$i - 1];
            $p3 = $points[$i];
            $j = self::jerkAt($p0, $p1, $p2, $p3);
            if ($j !== null) {
                $jerks[] = $j;
            }
        }
        if (empty($jerks)) {
            return 0.0;
        }
        $max = max($jerks);
        if ($max <= 0.0) {
            return 0.0;
        }
        // Bin into 16 buckets.
        $bins = array_fill(0, 16, 0);
        foreach ($jerks as $j) {
            $idx = (int) min(15, floor(($j / $max) * 16));
            $bins[$idx]++;
        }
        $total = array_sum($bins);
        $entropy = 0.0;
        foreach ($bins as $count) {
            if ($count === 0) {
                continue;
            }
            $p = $count / $total;
            $entropy -= $p * log($p, 2);
        }

        return $entropy;
    }

    private static function jerkAt(array $p0, array $p1, array $p2, array $p3): ?float
    {
        $t0 = (float) ($p0['t'] ?? 0);
        $t3 = (float) ($p3['t'] ?? 0);
        if ($t3 <= $t0) {
            return null;
        }
        $dt = ($t3 - $t0) / 3.0;
        if ($dt <= 0) {
            return null;
        }
        $a0 = self::accel($p0, $p1, $p2);
        $a1 = self::accel($p1, $p2, $p3);
        if ($a0 === null || $a1 === null) {
            return null;
        }

        return abs($a1 - $a0) / $dt;
    }

    private static function accel(array $p0, array $p1, array $p2): ?float
    {
        $t0 = (float) ($p0['t'] ?? 0);
        $t1 = (float) ($p1['t'] ?? 0);
        $t2 = (float) ($p2['t'] ?? 0);
        $dt1 = $t1 - $t0;
        $dt2 = $t2 - $t1;
        if ($dt1 <= 0 || $dt2 <= 0) {
            return null;
        }
        $v1 = self::distance($p0, $p1) / $dt1;
        $v2 = self::distance($p1, $p2) / $dt2;

        return ($v2 - $v1) / (($dt1 + $dt2) / 2.0);
    }

    private static function distance(array $a, array $b): float
    {
        $dx = (float) ($a['x'] ?? 0) - (float) ($b['x'] ?? 0);
        $dy = (float) ($a['y'] ?? 0) - (float) ($b['y'] ?? 0);

        return sqrt($dx * $dx + $dy * $dy);
    }

    /**
     * Walk backwards from the final sample while consecutive points stay
     * within `$radiusPx` of the last point — that span is the completion
     * dwell. Hand jitter on a mouse / trackpad / touchpad easily produces
     * 4-7 px of micro-movement during a "hold", so the tolerance must be
     * wider than the literal pixel-perfect 2 px we used to require.
     *
     * @param  array<int, array<string, mixed>>  $points
     */
    public static function completionDwellMs(array $points, float $radiusPx = 8.0): float
    {
        $n = count($points);
        if ($n < 3) {
            return 0.0;
        }
        $tail = (float) ($points[$n - 1]['t'] ?? 0);
        $last = $points[$n - 1];
        for ($i = $n - 2; $i >= 0; $i--) {
            if (self::distance($points[$i], $last) > $radiusPx) {
                return $tail - (float) ($points[$i + 1]['t'] ?? $tail);
            }
        }

        return $tail - (float) ($points[0]['t'] ?? $tail);
    }

    private static function scoreConfidence(
        float $shapeDistance,
        float $tolerance,
        float $jitter,
        float $jerkEntropy
    ): float {
        $shapeScore = max(0.0, 1.0 - $shapeDistance / max(1.0, $tolerance));
        $jitterScore = min(1.0, $jitter * 4.0);
        $entropyScore = min(1.0, $jerkEntropy / 4.0);

        return ($shapeScore * 0.5) + ($jitterScore * 0.25) + ($entropyScore * 0.25);
    }
}
