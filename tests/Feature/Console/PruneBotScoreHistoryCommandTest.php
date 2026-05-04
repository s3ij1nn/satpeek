<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\BotScoreHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pins the retention contract for `bot_score_history`:
 *
 *   - Rows newer than the retention window survive
 *   - Rows older than the retention window are deleted
 *   - --dry-run reports the count without mutating
 *   - --days option changes the cutoff
 *   - --chunk option drives batched delete (verified by seeding more rows
 *     than chunk size and asserting all old rows still get pruned)
 */
class PruneBotScoreHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_rows_older_than_retention_window(): void
    {
        $oldRow = $this->seedRow(Carbon::now()->subDays(95));
        $boundaryRow = $this->seedRow(Carbon::now()->subDays(91));
        $recentRow = $this->seedRow(Carbon::now()->subDays(5));

        $this->artisan('satpeek:prune-bot-score-history')->assertSuccessful();

        $this->assertNull(BotScoreHistory::find($oldRow->id));
        $this->assertNull(BotScoreHistory::find($boundaryRow->id));
        $this->assertNotNull(BotScoreHistory::find($recentRow->id));
    }

    public function test_dry_run_reports_count_without_deleting(): void
    {
        $oldRow = $this->seedRow(Carbon::now()->subDays(120));

        $this->artisan('satpeek:prune-bot-score-history', ['--dry-run' => true])
            ->expectsOutputToContain('would prune 1')
            ->assertSuccessful();

        $this->assertNotNull(BotScoreHistory::find($oldRow->id));
    }

    public function test_custom_days_option_changes_cutoff(): void
    {
        $sevenDaysOld = $this->seedRow(Carbon::now()->subDays(7));

        $this->artisan('satpeek:prune-bot-score-history')->assertSuccessful();
        $this->assertNotNull(BotScoreHistory::find($sevenDaysOld->id));

        $this->artisan('satpeek:prune-bot-score-history', ['--days' => 5])->assertSuccessful();
        $this->assertNull(BotScoreHistory::find($sevenDaysOld->id));
    }

    public function test_chunked_delete_finishes_when_old_rows_exceed_chunk_size(): void
    {
        // 5 old rows, chunk size 2 -> should still drain in 3 batches.
        $oldIds = [];
        for ($i = 0; $i < 5; $i++) {
            $oldIds[] = $this->seedRow(Carbon::now()->subDays(100))->id;
        }

        $this->artisan('satpeek:prune-bot-score-history', ['--chunk' => 2])
            ->assertSuccessful();

        foreach ($oldIds as $id) {
            $this->assertNull(BotScoreHistory::find($id));
        }
    }

    public function test_zero_state_reports_nothing_to_prune(): void
    {
        $this->artisan('satpeek:prune-bot-score-history')
            ->expectsOutputToContain('nothing to prune')
            ->assertSuccessful();
    }

    private function seedRow(Carbon $createdAt): BotScoreHistory
    {
        $row = BotScoreHistory::create([
            'user_id' => null,
            'score' => 0.42,
            'tier' => 'trust',
            'signals' => [],
            'created_at' => $createdAt,
        ]);
        $row->forceFill(['created_at' => $createdAt])->save();

        return $row;
    }
}
