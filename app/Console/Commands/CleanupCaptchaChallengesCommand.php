<?php

namespace App\Console\Commands;

use App\Models\CaptchaChallenge;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nightly housekeeping for `captcha_challenges`.
 *
 * Two phases:
 *   1. EXPIRE — flip `issued` rows whose `expires_at` is in the past to
 *      `expired`. ChallengeVerifier already does this lazily on access,
 *      but rows that are issued and never re-touched (user closed the
 *      tab, ad never solved) accumulate forever otherwise. Surfacing
 *      them as `expired` keeps reporting truthful and lets the prune
 *      step sweep them on a deterministic schedule.
 *
 *   2. PRUNE — delete verified / rejected / expired rows older than
 *      `--days` (default 30). The captcha trace + raw shape are not
 *      regulatory data; once a session is resolved the row is only
 *      useful for short-window bot-score recompute, which is well
 *      under 30 days.
 *
 * `--dry-run` reports counts without mutating anything — handy when an
 * operator runs the command by hand mid-day to estimate the next nightly
 * sweep's impact.
 */
class CleanupCaptchaChallengesCommand extends Command
{
    protected $signature = 'satpeek:cleanup-captcha
                            {--days=30 : prune resolved rows older than this many days}
                            {--dry-run : report counts without mutating}';

    protected $description = 'Expire stale issued captcha challenges and prune long-resolved rows.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $now = Carbon::now();
        $cutoff = $now->copy()->subDays($days);

        $expireQuery = CaptchaChallenge::query()
            ->where('status', 'issued')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now);

        $pruneQuery = CaptchaChallenge::query()
            ->whereIn('status', ['verified', 'rejected', 'expired'])
            ->where('updated_at', '<', $cutoff);

        $expireCount = (int) $expireQuery->count();
        $pruneCount = (int) $pruneQuery->count();

        if ($dryRun) {
            $this->info("[dry-run] would expire {$expireCount} issued rows past their TTL");
            $this->info("[dry-run] would prune {$pruneCount} resolved rows older than {$days} days");

            return self::SUCCESS;
        }

        if ($expireCount > 0) {
            $expireQuery->update([
                'status' => 'expired',
                'rejection_reason' => 'ttl_exceeded_by_cleanup',
                'resolved_at' => $now,
            ]);
        }

        $pruneDeleted = $pruneCount > 0 ? (int) $pruneQuery->delete() : 0;

        $this->info("expired {$expireCount} stale issued challenges");
        $this->info("pruned {$pruneDeleted} resolved challenges older than {$days} days");

        return self::SUCCESS;
    }
}
