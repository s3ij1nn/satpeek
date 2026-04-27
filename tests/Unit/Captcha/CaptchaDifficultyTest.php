<?php

declare(strict_types=1);

namespace Tests\Unit\Captcha;

use App\Captcha\TrajectoryTraceProvider;
use Tests\TestCase;

/**
 * Locks the contract that PolicyEnforcer::captchaDifficulty actually
 * shapes the issued challenge. Without this, the difficulty knob would
 * silently no-op and a suspect / likely_bot user would face the same
 * curve as a trust user — defeating the point of the tier system.
 */
class CaptchaDifficultyTest extends TestCase
{
    public function test_difficulty_levels_widen_amplitude_monotonically(): void
    {
        $provider = new TrajectoryTraceProvider;
        $sessionId = 'test-session';
        $viewport = ['w' => 320, 'h' => 240];

        // Re-issue many challenges per difficulty and check that the
        // average amplitude is monotonically increasing. Single samples
        // would be too noisy because amplitude has a random component.
        $samplesByDifficulty = [];
        foreach ([1, 2, 3] as $difficulty) {
            $sum = 0;
            $n = 30;
            for ($i = 0; $i < $n; $i++) {
                $issued = $provider->issue($sessionId, null, $viewport, $difficulty);
                $sum += (int) $issued['payload']['amplitude'];
            }
            $samplesByDifficulty[$difficulty] = $sum / $n;
        }

        $this->assertGreaterThan($samplesByDifficulty[1], $samplesByDifficulty[2]);
        $this->assertGreaterThan($samplesByDifficulty[2], $samplesByDifficulty[3]);
    }

    public function test_difficulty_levels_lift_frequency_floor(): void
    {
        $provider = new TrajectoryTraceProvider;

        // Frequency is `(rng() % 3) + 1 + (difficulty - 1)`. Min frequency
        // by difficulty: 1 → 1, 2 → 2, 3 → 3. So even the cheapest random
        // draw at difficulty 3 is >= 3.
        $minByDifficulty = [];
        foreach ([1, 2, 3] as $difficulty) {
            $min = PHP_INT_MAX;
            for ($i = 0; $i < 30; $i++) {
                $issued = $provider->issue('s', null, ['w' => 320, 'h' => 240], $difficulty);
                $min = min($min, (int) $issued['payload']['frequency']);
            }
            $minByDifficulty[$difficulty] = $min;
        }

        $this->assertGreaterThanOrEqual(1, $minByDifficulty[1]);
        $this->assertGreaterThanOrEqual(2, $minByDifficulty[2]);
        $this->assertGreaterThanOrEqual(3, $minByDifficulty[3]);
    }

    public function test_out_of_range_difficulty_is_clamped(): void
    {
        $provider = new TrajectoryTraceProvider;

        // 0 and -5 should clamp UP to 1 (treat as trust).
        // 99 should clamp DOWN to 3 (max likely_bot difficulty).
        // Without clamping, a typo'd difficulty=99 would mint a 50× amplitude
        // curve no human could trace.
        for ($i = 0; $i < 20; $i++) {
            $clampedLow = $provider->issue('s', null, ['w' => 320, 'h' => 240], 0);
            $clampedHigh = $provider->issue('s', null, ['w' => 320, 'h' => 240], 99);

            // Trust band caps at amplitude 90 (rng() % 60 + 30).
            $this->assertLessThanOrEqual(90, (int) $clampedLow['payload']['amplitude']);
            // Difficulty 3 caps at amplitude 180 (90 × 2.0 scale).
            $this->assertLessThanOrEqual(180, (int) $clampedHigh['payload']['amplitude']);
            // Difficulty 3 lifts frequency floor to 3.
            $this->assertGreaterThanOrEqual(3, (int) $clampedHigh['payload']['frequency']);
        }
    }

    public function test_default_difficulty_is_one(): void
    {
        $provider = new TrajectoryTraceProvider;

        // Calling issue() without an explicit difficulty must equal
        // calling it with 1. Pre-auth captchas (login / register form)
        // hit this code path because there's no user to score yet.
        for ($i = 0; $i < 20; $i++) {
            $defaulted = $provider->issue('s', null, ['w' => 320, 'h' => 240]);
            $explicit = $provider->issue('s', null, ['w' => 320, 'h' => 240], 1);
            // We can't compare amplitude exactly because seed differs per
            // call, but both must be inside the trust band [30, 90].
            $this->assertGreaterThanOrEqual(30, (int) $defaulted['payload']['amplitude']);
            $this->assertLessThanOrEqual(90, (int) $defaulted['payload']['amplitude']);
            $this->assertGreaterThanOrEqual(30, (int) $explicit['payload']['amplitude']);
            $this->assertLessThanOrEqual(90, (int) $explicit['payload']['amplitude']);
        }
    }
}
