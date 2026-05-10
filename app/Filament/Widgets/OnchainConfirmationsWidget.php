<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Operator visibility into Withdrawals waiting on chain finality.
 * Counts everything in `broadcast` status (the watcher hasn't yet
 * promoted to `sent`) and surfaces the oldest broadcast age.
 *
 * Catches the silent failure mode where the
 * `WatchOnchainConfirmationsJob` cron is stalled (queue worker
 * dead, RPC down for hours, hot-wallet recipient permanently
 * blacklisted): in-flight count rises monotonically and the
 * oldest-broadcast age crosses the warning threshold instead of
 * staying ~60 s as it should under normal operation.
 *
 * Two cheap aggregate queries against `withdrawals`. Empty result
 * (no broadcast rows) collapses to a single "queue clear" stat —
 * the dashboard space is precious so we don't render zero rows.
 */
class OnchainConfirmationsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    /** Oldest-broadcast threshold (minutes) above which we flag warning. */
    private const WARN_AFTER_MINUTES = 30;

    /** Above this we flag danger — likely a stalled watcher. */
    private const STALE_AFTER_MINUTES = 120;

    protected function getStats(): array
    {
        // DB::table (vs Withdrawal::query()) so the selectRaw aliases
        // come back on a stdClass — Eloquent would type-fight phpstan
        // about model-property access for the synthetic `cnt` /
        // `oldest` columns.
        $row = DB::table('withdrawals')
            ->where('status', 'broadcast')
            ->where('payout_method', 'like', 'onchain_%')
            ->whereNotNull('broadcast_at')
            ->selectRaw('count(*) as cnt, min(broadcast_at) as oldest')
            ->first();

        $count = (int) ($row->cnt ?? 0);
        if ($count === 0) {
            return [
                Stat::make('Onchain awaiting finality', '0')
                    ->description('all confirmed')
                    ->descriptionIcon('heroicon-m-check')
                    ->color('success'),
            ];
        }

        $oldestStr = $row !== null ? (string) ($row->oldest ?? '') : '';
        $oldest = $oldestStr !== '' ? Carbon::parse($oldestStr) : null;
        $oldestAgeMin = $oldest !== null ? (int) $oldest->diffInMinutes(now()) : 0;

        if ($oldestAgeMin >= self::STALE_AFTER_MINUTES) {
            $color = 'danger';
            $description = "oldest broadcast {$oldestAgeMin} min — watcher likely stalled";
            $icon = 'heroicon-m-exclamation-triangle';
        } elseif ($oldestAgeMin >= self::WARN_AFTER_MINUTES) {
            $color = 'warning';
            $description = "oldest broadcast {$oldestAgeMin} min — slower than usual";
            $icon = 'heroicon-m-clock';
        } else {
            $color = 'success';
            $description = "oldest broadcast {$oldestAgeMin} min — within finality window";
            $icon = 'heroicon-m-arrow-path';
        }

        return [
            Stat::make('Onchain awaiting finality', (string) $count)
                ->description($description)
                ->descriptionIcon($icon)
                ->color($color)
                ->url(url('/admin/withdrawals?tableFilters[status][value]=broadcast')),
        ];
    }
}
