<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\BotScoreHistory;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily count of ScoreEngine evaluations grouped by tier — 14-day window.
 *
 * Pairs with `BotTierDistributionWidget` (the live snapshot): this one
 * shows the trail. A sudden spike in `likely_bot` or `banned` evaluations
 * means an attack wave hit; a flat-line gap means the score engine
 * stopped firing (signal pipeline broken). Both modes are operator-
 * actionable and invisible from the live snapshot alone.
 *
 * One GROUP BY (day, tier) query against `bot_score_history`.
 * Portable SQL — date() on SQLite for the test harness, to_char on
 * Postgres for prod, same shape as PayoutVolumeChartWidget.
 */
class BotTierTrendChartWidget extends ChartWidget
{
    protected ?string $heading = 'Bot tier evaluations — last 14 days';

    protected ?string $description = 'Count of ScoreEngine runs per day, grouped by resulting tier.';

    protected static ?int $sort = 5;

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

        $driver = DB::connection()->getDriverName();
        $dayExpr = $driver === 'sqlite'
            ? 'date(created_at)'
            : "to_char(created_at, 'YYYY-MM-DD')";

        $rows = BotScoreHistory::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->selectRaw("{$dayExpr} as day, tier, count(*) as cnt")
            ->groupBy('day', 'tier')
            ->get();

        // Pivot day,tier → cnt with zero-fill so each series is the same
        // length as $labels. Day key matches the pivot expression's output.
        // Read aggregated columns via getAttribute() so PHPStan / Larastan
        // doesn't trip on day / tier / cnt being absent from the model's
        // declared property set (they're synthesised by the SELECT, not
        // table columns).
        $byDay = [];
        foreach ($rows as $r) {
            $day = (string) $r->getAttribute('day');
            $tier = (string) $r->getAttribute('tier');
            $cnt = (int) $r->getAttribute('cnt');
            $byDay[$day][$tier] = $cnt;
        }

        $labels = [];
        $trust = [];
        $suspect = [];
        $likelyBot = [];
        $banned = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->format('Y-m-d');
            $labels[] = $d->format('M j');
            $trust[] = (int) ($byDay[$key]['trust'] ?? 0);
            $suspect[] = (int) ($byDay[$key]['suspect'] ?? 0);
            $likelyBot[] = (int) ($byDay[$key]['likely_bot'] ?? 0);
            $banned[] = (int) ($byDay[$key]['banned'] ?? 0);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Trust',
                    'data' => $trust,
                    'borderColor' => 'rgb(52, 211, 153)', // mint
                    'backgroundColor' => 'rgba(52, 211, 153, 0.10)',
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Suspect',
                    'data' => $suspect,
                    'borderColor' => 'rgb(245, 158, 11)', // amber
                    'backgroundColor' => 'rgba(245, 158, 11, 0.10)',
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Likely bot',
                    'data' => $likelyBot,
                    'borderColor' => 'rgb(251, 113, 133)', // rose
                    'backgroundColor' => 'rgba(251, 113, 133, 0.10)',
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Banned',
                    'data' => $banned,
                    'borderColor' => 'rgb(220, 38, 38)', // deep red
                    'backgroundColor' => 'rgba(220, 38, 38, 0.10)',
                    'tension' => 0.3,
                ],
            ],
        ];
    }
}
