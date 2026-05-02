<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\BalanceLedger;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily total of POSITIVE ledger deltas grouped by reason — i.e.
 * "how much did SatPeek pay out per surface per day". The negative
 * side (withdrawals, ad funding) is intentionally excluded; that's
 * a different question handled by InFlightWithdrawalsWidget.
 *
 * 14-day window, three series (PTC view / shortlink / internal
 * article reads). One grouped GROUP BY DATE(created_at), reason
 * query — runs in milliseconds against the `(reason, created_at)`
 * shape we keep on `balance_ledgers`.
 *
 * The chart label uses sat (not USD) so the operator stays in the
 * platform's native unit. Conversion to USD is a per-screen
 * concern handled at the user-facing surfaces.
 */
class PayoutVolumeChartWidget extends ChartWidget
{
    protected ?string $heading = 'Daily payout volume (sat) — last 14 days';

    protected ?string $description = 'Sum of positive ledger deltas grouped by earning surface.';

    protected static ?int $sort = 4;

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $start = Carbon::now()->subDays(13)->startOfDay();
        $end = Carbon::now()->endOfDay();

        // SQLite + Postgres both support date_trunc('day', ...) → cast
        // to a string when extracted. Using DB::raw with a portable
        // expression keeps the test suite (sqlite :memory:) and prod
        // (Postgres) on the same query.
        $driver = DB::connection()->getDriverName();
        $dayExpr = $driver === 'sqlite'
            ? "date(created_at)"
            : "to_char(created_at, 'YYYY-MM-DD')";

        $rows = BalanceLedger::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->where('delta_sat', '>', 0)
            ->whereIn('reason', ['ptc_view', 'shortlink', 'internal_article'])
            ->selectRaw("{$dayExpr} as day, reason, sum(delta_sat) as sat")
            ->groupBy('day', 'reason')
            ->get();

        // Pivot into [day][reason] = sat, defaulting missing cells to 0
        // so each series has the same x-axis length.
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[(string) $r->day][$r->reason] = (int) $r->sat;
        }

        $labels = [];
        $ptc = [];
        $shortlink = [];
        $article = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->format('Y-m-d');
            $labels[] = $d->format('M j');
            $ptc[] = (int) ($byDay[$key]['ptc_view'] ?? 0);
            $shortlink[] = (int) ($byDay[$key]['shortlink'] ?? 0);
            $article[] = (int) ($byDay[$key]['internal_article'] ?? 0);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'PTC views',
                    'data' => $ptc,
                    'borderColor' => 'rgb(245, 158, 11)', // amber
                    'backgroundColor' => 'rgba(245, 158, 11, 0.10)',
                    'tension' => 0.3,
                    'fill' => true,
                ],
                [
                    'label' => 'Shortlinks',
                    'data' => $shortlink,
                    'borderColor' => 'rgb(52, 211, 153)', // mint
                    'backgroundColor' => 'rgba(52, 211, 153, 0.10)',
                    'tension' => 0.3,
                    'fill' => true,
                ],
                [
                    'label' => 'Read articles',
                    'data' => $article,
                    'borderColor' => 'rgb(96, 165, 250)', // blue
                    'backgroundColor' => 'rgba(96, 165, 250, 0.10)',
                    'tension' => 0.3,
                    'fill' => true,
                ],
            ],
        ];
    }
}
