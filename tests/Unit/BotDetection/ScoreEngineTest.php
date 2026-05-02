<?php

namespace Tests\Unit\BotDetection;

use App\BotDetection\ScoreEngine;
use App\BotDetection\Signals\Signal;
use App\Models\BotScoreHistory;
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

    public function test_each_evaluate_appends_a_bot_score_history_row(): void
    {
        config()->set('satpeek.bot_score.weights', ['a' => 1.0]);
        config()->set('satpeek.bot_score.ban', 0.85);
        config()->set('satpeek.bot_score.likely_bot', 0.60);
        config()->set('satpeek.bot_score.suspect', 0.30);

        $engine = new ScoreEngine([$this->fakeSignal('a', 0.40)]);
        $user = User::factory()->create();

        // Three back-to-back evaluations should produce three history rows,
        // even though `bot_scores` only ever holds one (latest) row per user.
        // updateOrCreate semantics on the live row are intentional; the
        // dashboard-trend widget needs the trail.
        $engine->evaluate($user);
        $engine->evaluate($user);
        $engine->evaluate($user);

        $rows = BotScoreHistory::where('user_id', $user->id)->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('suspect', $row->tier);
            $this->assertEqualsWithDelta(0.40, (float) $row->score, 0.001);
        }
    }

    private function fakeSignal(string $name, float $value): Signal
    {
        return new class($name, $value) implements Signal
        {
            public function __construct(private string $n, private float $v) {}

            public function name(): string
            {
                return $this->n;
            }

            public function evaluate(User $user): array
            {
                return ['score' => $this->v, 'detail' => []];
            }
        };
    }
}
