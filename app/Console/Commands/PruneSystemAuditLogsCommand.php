<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SystemAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nightly housekeeping for `system_audit_logs`.
 *
 * Without a sweep the table grows unboundedly — every cron / job
 * failure appends a row, and a sustained outage (e.g. RPC down for
 * a day) would mint ~1440 rows for a single chain (one per
 * watcher tick). 90 days is the same retention window
 * `bot_score_history` uses, which gives the operator ample lookback
 * for post-incident review without bloating the table.
 *
 *   --days       prune rows older than this many days (default 90)
 *   --dry-run    report row count without deleting
 *   --chunk      delete in chunks of this size (default 5000)
 *
 * Mirrors {@see PruneBotScoreHistoryCommand} — same chunked delete
 * pattern + same "Postgres has no DELETE ... LIMIT, so pluck IDs
 * first" workaround.
 */
class PruneSystemAuditLogsCommand extends Command
{
    protected $signature = 'satpeek:prune-system-audit-logs
                            {--days=90 : retention window in days}
                            {--dry-run : report count without deleting}
                            {--chunk=5000 : per-iteration delete size}';

    protected $description = 'Prune system_audit_logs rows older than the retention window.';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('satpeek.system_audit_log_retention_days', 90)));
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(100, (int) $this->option('chunk'));
        $cutoff = Carbon::now()->subDays($days);

        $candidateCount = (int) SystemAuditLog::query()
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

        $deleted = 0;
        do {
            $ids = SystemAuditLog::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id')
                ->all();
            if ($ids === []) {
                break;
            }
            $batch = (int) SystemAuditLog::query()
                ->whereIn('id', $ids)
                ->delete();
            $deleted += $batch;
        } while ($batch >= $chunk);

        $this->info("pruned {$deleted} rows older than {$days} days");

        return self::SUCCESS;
    }
}
