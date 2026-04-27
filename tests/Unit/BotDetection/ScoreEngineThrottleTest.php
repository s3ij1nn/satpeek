<?php

declare(strict_types=1);

namespace Tests\Unit\BotDetection;

use App\BotDetection\ScoreEngine;
use App\BotDetection\Signals\Signal;
use App\Models\BotScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks the throttle contract on `evaluateThrottled()`. Production call
 * sites (UserIpObserver, the captcha verify paths in the future) hit this
 * unconditionally on every event; the throttle is what keeps the signal
 * sweep from turning into a per-request DB stampede.
 */
class ScoreEngineThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_call_evaluates_when_no_prior_row_exists(): void
    {
        $user = User::factory()->create();
        $signal = $this->countingSignal('test', score: 0.1);
        $engine = new ScoreEngine([$signal]);

        $row = $engine->evaluateThrottled($user, minIntervalSeconds: 300);

        $this->assertSame(1, $signal->callCount);
        $this->assertSame($user->id, $row->user_id);
    }

    public function test_re_invocation_inside_window_short_circuits(): void
    {
        $user = User::factory()->create();
        $signal = $this->countingSignal('test', score: 0.1);
        $engine = new ScoreEngine([$signal]);

        $engine->evaluateThrottled($user, minIntervalSeconds: 300);
        $engine->evaluateThrottled($user, minIntervalSeconds: 300);
        $engine->evaluateThrottled($user, minIntervalSeconds: 300);

        // Only the first call ran the signal. Subsequent calls inside the
        // 300 s window short-circuit on the existing BotScore row.
        $this->assertSame(1, $signal->callCount);
    }

    public function test_re_invocation_after_window_re_evaluates(): void
    {
        $user = User::factory()->create();
        $signal = $this->countingSignal('test', score: 0.1);
        $engine = new ScoreEngine([$signal]);

        $engine->evaluateThrottled($user, minIntervalSeconds: 60);

        // Backdate the existing row so the throttle window has expired.
        BotScore::query()
            ->where('user_id', $user->id)
            ->update(['last_evaluated_at' => Carbon::now()->subSeconds(120)]);

        $engine->evaluateThrottled($user, minIntervalSeconds: 60);

        $this->assertSame(2, $signal->callCount);
    }

    public function test_min_interval_falls_back_to_config_when_omitted(): void
    {
        config()->set('satpeek.bot_score.min_reevaluate_interval_seconds', 7200); // 2 h
        $user = User::factory()->create();
        $signal = $this->countingSignal('test', score: 0.1);
        $engine = new ScoreEngine([$signal]);

        $engine->evaluateThrottled($user);

        // 1 h ago — within the 2 h config window.
        BotScore::query()
            ->where('user_id', $user->id)
            ->update(['last_evaluated_at' => Carbon::now()->subSeconds(3600)]);

        $engine->evaluateThrottled($user);

        $this->assertSame(1, $signal->callCount);
    }

    /**
     * Returns a stub Signal that records how many times `evaluate()` was
     * called and yields a fixed score per call. Lets us assert the
     * throttle behaviour without standing up the full signal stack.
     */
    private function countingSignal(string $name, float $score): Signal
    {
        return new class($name, $score) implements Signal
        {
            public int $callCount = 0;

            public function __construct(private readonly string $sigName, private readonly float $score) {}

            public function name(): string
            {
                return $this->sigName;
            }

            public function evaluate(User $user): array
            {
                $this->callCount++;

                return ['score' => $this->score, 'detail' => []];
            }
        };
    }
}
