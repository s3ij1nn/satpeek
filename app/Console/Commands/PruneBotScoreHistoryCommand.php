<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BotScoreHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nightly housekeeping for `bot_score_history`.
 *
 * ScoreEngine appends one row per evaluate() call — that's the login + register
 * paths AND the captcha-success path, so a moderately active platform mints a
 * row per user per ~30 minutes of engaged session. The table is tier-trend
 * fuel for the dashboard widgets and the /up `bot_detection` probe; nothing
 * older than ~90 days is operationally useful (the widgets render 14-day and
 * tier-distribution windows; the probe wants 24-h).
 *
 * Without a sweep the table grows unboundedly and the per-row signal JSON
 * blob isn't tiny. Default retention 90 days gives ample buffer for the
 * widest dashboard window (and a 30-day safety margin past the last
 * pre-2026-Q3 signal-set rotation) while keeping the table within working-
 * memory range on a modest Postgres instance.
 *
 *   --days       prune rows older than this many days (default 90)
 *   --dry-run    report row count without deleting
 *   --chunk      delete in chunks of this size (default 5000) to avoid
 *                long-held locks on Postgres under concurrent inserts
 */
class PruneBotScoreHistoryCommand extends Command
{
    protected $signature = 'satpeek:prune-bot-score-history
                            {--days=90 : retention window in days}
                            {--dry-run : report count without deleting}
                            {--chunk=5000 : per-iteration delete size}';

    protected $description = 'Prune bot_score_history rows older than the retention window.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(100, (int) $this->option('chunk'));
        $cutoff = Carbon::now()->subDays($days);

        $candidateCount = (int) BotScoreHistory::query()
            ->where('created_at', '<', $cutoff)
            ->count();

        if ($dryRun) {
            $this->info("[dry-run] would prune {$candidateCount} rows older than {$days} days");

            return self::SUCCESS;
        }

        if ($candidateCount === 0) {
            $this->info('nothing to prune');

            return self::SUCCESS;
        }

        // Chunked delete: a single DELETE on a multi-million-row table would
        // hold a long lock. The per-batch approach lets concurrent inserts
        // (ScoreEngine::evaluate is on the auth + captcha hot path) interleave.
        // Postgres doesn't support DELETE ... LIMIT, so we pluck IDs first
        // and DELETE WHERE id IN (...) — works portably on SQLite + Postgres.
        $deleted = 0;
        do {
            $ids = BotScoreHistory::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id')
                ->all();
            if ($ids === []) {
                break;
            }
            $batch = (int) BotScoreHistory::query()
                ->whereIn('id', $ids)
                ->delete();
            $deleted += $batch;
        } while ($batch >= $chunk);

        $this->info("pruned {$deleted} rows older than {$days} days");

        return self::SUCCESS;
    }
}
