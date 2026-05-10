<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\HotWalletLowBalanceAlert;
use App\Models\User;
use App\Payout\WalletBalanceMonitorRegistry;
use App\Payout\WalletBalanceUnavailableException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Polls every registered {@see WalletBalanceMonitor}; emails admins
 * the moment a monitor flips to `down` (gap < 0 OR `available()`
 * throws). Companion to the `/up` probe — `/up` lets external
 * monitoring page-out; this lets operators react without configuring
 * external tools.
 *
 * Idempotency: the alert state is cached as a sorted CSV of
 * down-currency codes for 6 h. A re-run with the same set is a
 * no-op (re-spamming would train the operator to ignore the alert).
 * Recovery: when the set shrinks (operator topped up) the cache
 * key is updated; when the next monitor flips to down, a fresh
 * alert is sent. A clean run (no down currencies) clears the
 * cache so the next degradation re-triggers immediately.
 *
 * Scheduled every 15 minutes — the watcher cron already runs every
 * minute and would catch a bad broadcast within ~60 s; 15 minutes
 * is enough lead time to avert most "queue stalls because wallet
 * is empty" incidents without spamming on every transient RPC blip.
 */
class HotWalletLowBalanceCommand extends Command
{
    protected $signature = 'satpeek:hot-wallet-alert {--dry-run : Print the down rows without queuing mail}';

    protected $description = 'Email admins when a hot-wallet monitor flips to down (over-committed or RPC failure).';

    /** Cache key for the previous alert's down-set. */
    private const ALERT_STATE_KEY = 'hot-wallet-alert:last-down-set';

    /** Re-alert window — operator gets one email per 6 h per set. */
    private const ALERT_TTL_SECONDS = 21600;

    public function handle(WalletBalanceMonitorRegistry $registry): int
    {
        $monitors = $registry->all();
        if ($monitors === []) {
            $this->info('No hot-wallet monitors registered — nothing to check.');

            return self::SUCCESS;
        }

        $downRows = [];
        foreach ($monitors as $monitor) {
            $code = $monitor->currency();
            try {
                $available = $monitor->available();
            } catch (WalletBalanceUnavailableException) {
                $downRows[] = [
                    'code' => $code,
                    'status' => 'unavailable',
                    'available' => null,
                    'required' => null,
                    'gap' => null,
                ];

                continue;
            }
            $required = $monitor->required();
            $gap = bcsub($available, $required, 0);
            // Only `down` (gap < 0) — degraded is a "soon" signal that
            // the dashboard surfaces; alerting on it would page-out
            // way too often.
            if (bccomp($gap, '0', 0) < 0) {
                $downRows[] = [
                    'code' => $code,
                    'status' => 'down',
                    'available' => $available,
                    'required' => $required,
                    'gap' => $gap,
                ];
            }
        }

        if ($downRows === []) {
            // Clear the cache so a future degradation re-alerts
            // immediately (don't make the operator wait for the TTL
            // to expire after they recovered the wallet).
            Cache::forget(self::ALERT_STATE_KEY);
            $this->info('All hot-wallet monitors ok.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line(json_encode($downRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        // Idempotency check: same set of down currencies as last
        // alert → skip (operator was already paged for this state).
        $signature = $this->signature($downRows);
        $previous = (string) Cache::get(self::ALERT_STATE_KEY, '');
        if ($previous === $signature) {
            $this->info("Same down-set as previous alert ({$signature}) — skipping (cache TTL).");

            return self::SUCCESS;
        }

        $admins = User::query()
            ->where('is_admin', true)
            ->whereNotNull('email')
            ->get();
        if ($admins->isEmpty()) {
            $this->warn('Down currencies detected but no admin users to notify.');

            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->queue(new HotWalletLowBalanceAlert($downRows));
                $sent++;
            } catch (\Throwable $e) {
                $this->warn("Mail to {$admin->email} failed: {$e->getMessage()}");
            }
        }

        Cache::put(self::ALERT_STATE_KEY, $signature, self::ALERT_TTL_SECONDS);
        $this->info("Queued hot-wallet alert ({$signature}) to {$sent} admin user(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $downRows
     */
    private function signature(array $downRows): string
    {
        $codes = array_map(fn ($r): string => (string) $r['code'].':'.(string) $r['status'], $downRows);
        sort($codes);

        return implode(',', $codes);
    }
}
