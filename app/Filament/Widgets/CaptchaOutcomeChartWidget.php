<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\CaptchaChallenge;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 14-day pass / reject / expired stacked chart for captcha solves.
 *
 * Pairs with the read-only `/admin/captcha-challenges` triage table:
 * the table answers "why was THIS attempt rejected?", this widget
 * answers "what's the rejection trend across the platform?".
 *
 * Three signals an operator can act on:
 *   - rising reject rate → tune the captcha tolerance knobs (new
 *     bot wave the verifier handles too aggressively, or — opposite
 *     — too leniently)
 *   - rising expired rate → users abandoning the form before
 *     submitting (UX issue, not abuse)
 *   - flat-line on all three → ScoreEngine pipeline likely stalled,
 *     cross-check with `/up`'s `bot_detection` probe
 *
 * Single GROUP BY day,status query against the existing
 * `(status, created_at)` index. Portable SQL — date() on SQLite
 * for the test harness, to_char on Postgres for prod, same shape
 * as the existing PayoutVolumeChartWidget + BotTierTrendChartWidget.
 */
class CaptchaOutcomeChartWidget extends ChartWidget
{
    protected ?string $heading = 'Captcha outcomes — last 14 days';

    protected ?string $description = 'Daily count of resolved captcha attempts by outcome.';

    protected static ?int $sort = 6;

    protected function getType(): string
    {
        return 'bar';
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

        $rows = CaptchaChallenge::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->whereIn('status', ['verified', 'rejected', 'expired', 'consumed'])
            ->selectRaw("{$dayExpr} as day, status, count(*) as cnt")
            ->groupBy('day', 'status')
            ->get();

        // Pivot day → status → count with zero-fill so each series
        // has the same x-axis length. Using getAttribute() for the
        // synthesised columns to keep static analysis happy.
        $byDay = [];
        foreach ($rows as $r) {
            $day = (string) $r->getAttribute('day');
            $status = (string) $r->getAttribute('status');
            $cnt = (int) $r->getAttribute('cnt');
            $byDay[$day][$status] = $cnt;
        }

        $labels = [];
        $verified = [];
        $rejected = [];
        $expired = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->format('Y-m-d');
            $labels[] = $d->format('M j');
            // `consumed` rolls into the verified bucket — both mean
            // "user passed" from a triage perspective.
            $verified[] = (int) ($byDay[$key]['verified'] ?? 0)
                + (int) ($byDay[$key]['consumed'] ?? 0);
            $rejected[] = (int) ($byDay[$key]['rejected'] ?? 0);
            $expired[] = (int) ($byDay[$key]['expired'] ?? 0);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Verified',
                    'data' => $verified,
                    'backgroundColor' => 'rgba(52, 211, 153, 0.85)', // mint
                    'borderColor' => 'rgb(52, 211, 153)',
                ],
                [
                    'label' => 'Rejected',
                    'data' => $rejected,
                    'backgroundColor' => 'rgba(251, 113, 133, 0.85)', // rose
                    'borderColor' => 'rgb(251, 113, 133)',
                ],
                [
                    'label' => 'Expired',
                    'data' => $expired,
                    'backgroundColor' => 'rgba(170, 180, 194, 0.85)', // gray
                    'borderColor' => 'rgb(170, 180, 194)',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true],
            ],
        ];
    }
}
