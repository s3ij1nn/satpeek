<?php

namespace Tests\Unit\BotDetection;

use App\BotDetection\ScoreEngine;
use App\BotDetection\Signals\Signal;
use App\Models\BotScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoreEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_weighted_sum_normalises_to_unit_interval(): void
    {
        config()->set('satpeek.bot_score.weights', [
            'a' => 0.6,
            'b' => 0.4,
        ]);
        config()->set('satpeek.bot_score.suspect', 0.30);
        config()->set('satpeek.bot_score.likely_bot', 0.60);
        config()->set('satpeek.bot_score.ban', 0.85);

        $signals = [
            $this->fakeSignal('a', 1.0),
            $this->fakeSignal('b', 0.0),
        ];
        $engine = new ScoreEngine($signals);
        $user = User::factory()->create();

        $score = $engine->evaluate($user);
        $this->assertEqualsWithDelta(0.6, (float) $score->score, 0.001);
        $this->assertSame('likely_bot', $score->tier);
    }

    public function test_score_above_ban_threshold_marks_user_banned(): void
    {
        config()->set('satpeek.bot_score.weights', ['a' => 1.0]);
        config()->set('satpeek.bot_score.ban', 0.85);

        $engine = new ScoreEngine([$this->fakeSignal('a', 1.0)]);
        $user = User::factory()->create();
        $engine->evaluate($user);
        $user->refresh();
        $this->assertTrue($user->is_banned);
    }

    private function fakeSignal(string $name, float $value): Signal
    {
        return new class($name, $value) implements Signal {
            public function __construct(private string $n, private float $v) {}
            public function name(): string { return $this->n; }
            public function evaluate(User $user): array
            {
                return ['score' => $this->v, 'detail' => []];
            }
        };
    }
}
