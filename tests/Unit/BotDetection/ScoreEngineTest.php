<?php

namespace Tests\Unit\BotDetection;

use App\BotDetection\ScoreEngine;
use App\BotDetection\Signals\Signal;
use App\Models\BotScoreHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
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

    public function test_tier_escalation_dispatches_admin_notification(): void
    {
        config()->set('satpeek.bot_score.weights', ['a' => 1.0]);
        config()->set('satpeek.bot_score.ban', 0.85);
        config()->set('satpeek.bot_score.likely_bot', 0.60);
        config()->set('satpeek.bot_score.suspect', 0.30);

        $admin = User::factory()->create(['is_admin' => true, 'username' => 'opsadmin']);
        $user = User::factory()->create(['username' => 'suspect_user']);

        // First evaluation lands the user in `trust` (raw 0.10) — no
        // previous tier so no notification fires (the very first
        // evaluation isn't a transition).
        $low = new ScoreEngine([$this->fakeSignal('a', 0.10)]);
        $low->evaluate($user);
        $this->assertSame(0, DatabaseNotification::query()
            ->where('notifiable_id', $admin->id)->count());

        // Second evaluation pushes them to `suspect` (raw 0.40) → 1
        // notification fans out to the admin inbox.
        $mid = new ScoreEngine([$this->fakeSignal('a', 0.40)]);
        $mid->evaluate($user);
        $notifications = DatabaseNotification::query()
            ->where('notifiable_id', $admin->id)->get();
        $this->assertCount(1, $notifications, 'admin must receive one tier-escalation notification');
        $payload = $notifications->first()->data;
        $this->assertSame('User flagged as suspect', $payload['title']);
        $this->assertStringContainsString('trust → suspect', $payload['body']);
        $this->assertStringContainsString('suspect_user', $payload['body']);
    }

    public function test_tier_de_escalation_does_not_dispatch_notification(): void
    {
        // De-escalation (signal noise abated) is intentionally silent —
        // we don't page the operator every time a flagged user goes
        // back to clean. Only escalations matter.
        config()->set('satpeek.bot_score.weights', ['a' => 1.0]);
        config()->set('satpeek.bot_score.ban', 0.85);
        config()->set('satpeek.bot_score.likely_bot', 0.60);
        config()->set('satpeek.bot_score.suspect', 0.30);

        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        // Land the user in suspect first (no escalation notification on
        // first eval).
        (new ScoreEngine([$this->fakeSignal('a', 0.40)]))->evaluate($user);
        // Drop them back to trust — must produce zero new notifications.
        DatabaseNotification::query()
            ->where('notifiable_id', $admin->id)->delete();

        (new ScoreEngine([$this->fakeSignal('a', 0.10)]))->evaluate($user);

        $this->assertSame(0, DatabaseNotification::query()
            ->where('notifiable_id', $admin->id)->count());
    }

    public function test_tier_notification_skips_self_when_admin_user_is_the_subject(): void
    {
        // Edge case: an admin who somehow gets bot-flagged shouldn't
        // get notified about their own escalation. The query filters
        // `id != $user->id` to handle this.
        config()->set('satpeek.bot_score.weights', ['a' => 1.0]);
        config()->set('satpeek.bot_score.ban', 0.85);
        config()->set('satpeek.bot_score.likely_bot', 0.60);
        config()->set('satpeek.bot_score.suspect', 0.30);

        $admin = User::factory()->create(['is_admin' => true]);

        // First evaluation lands in trust silently.
        (new ScoreEngine([$this->fakeSignal('a', 0.10)]))->evaluate($admin);
        // Escalate. With a single admin and no other recipients, the
        // notification must NOT bounce back to the subject.
        (new ScoreEngine([$this->fakeSignal('a', 0.40)]))->evaluate($admin);

        $this->assertSame(0, DatabaseNotification::query()
            ->where('notifiable_id', $admin->id)->count());
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
