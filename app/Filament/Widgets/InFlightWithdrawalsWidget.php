<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Withdrawal;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Operator dashboard at-a-glance: how much money is currently mid-flight
 * to FaucetPay vs sitting on hold for review. Catches the failure mode
 * where the cron stops dispatching (queue worker dead) — `In flight` rises
 * monotonically and the operator sees it without drilling into the
 * withdrawals list.
 *
 * Two cheap aggregate queries against `withdrawals`, both indexed by the
 * `(status, created_at)` composite added in
 * `2026_04_25_000009_create_withdrawals_table.php`.
 */
class InFlightWithdrawalsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // queued + processing — the actual "FaucetPay round-trip in progress"
        // bucket. Excludes 'hold' which is the manual-review queue (different
        // operator action: approve, not "wait it out").
        $inFlight = Withdrawal::whereIn('status', ['queued', 'processing'])
            ->selectRaw('count(*) as cnt, coalesce(sum(amount_sat), 0) as total_sat')
            ->first();
        $cnt = (int) ($inFlight->cnt ?? 0);
        $sat = (int) ($inFlight->total_sat ?? 0);

        $holdCnt = (int) Withdrawal::where('status', 'hold')->count();
        $failed24h = (int) Withdrawal::where('status', 'failed')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return [
            Stat::make('In flight', $cnt === 0 ? '0' : "{$cnt} (".number_format($sat).' sat)')
                ->description($cnt === 0 ? 'queue is drained' : 'queued + processing')
                ->descriptionIcon($cnt === 0 ? 'heroicon-m-check' : 'heroicon-m-arrow-path')
                ->color($cnt === 0 ? 'success' : 'warning')
                ->url(url('/admin/withdrawals?tableFilters[status][value]=processing')),

            Stat::make('Hold (review)', (string) $holdCnt)
                ->description($holdCnt === 0 ? 'nothing waiting' : 'requires admin approval')
                ->descriptionIcon('heroicon-m-pause')
                ->color($holdCnt === 0 ? 'gray' : 'warning')
                ->url(url('/admin/withdrawals?tableFilters[status][value]=hold')),

            Stat::make('Failed (24 h)', (string) $failed24h)
                ->description($failed24h === 0 ? 'clean window' : 'check FaucetPay outage')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failed24h === 0 ? 'success' : 'danger')
                ->url(url('/admin/withdrawals?tableFilters[status][value]=failed')),
        ];
    }
}
