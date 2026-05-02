<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\InternalArticleView;
use App\Models\PtcView;
use App\Models\ShortlinkClick;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Three counters across the earning surfaces — PTC views, shortlink
 * clicks, and internal article reads — each scoped to today vs the
 * previous 24 h. Helps the operator notice abrupt drops (queue dead,
 * provider outage, captcha too tight) or spikes (campaign launched,
 * bot wave) at a glance without drilling into each resource.
 *
 * `verified` only — pending / rejected don't count as earning
 * activity. Three lightweight COUNT queries; all three tables are
 * indexed on (status, created_at) or equivalent so the daily window
 * scan is bounded.
 */
class EarningActivityWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $today = Carbon::now()->startOfDay();
        $yesterday = Carbon::now()->subDay()->startOfDay();

        return [
            $this->stat(
                'PTC views (24 h)',
                PtcView::class,
                'ptc-views',
                $today,
                $yesterday,
            ),
            $this->stat(
                'Shortlink clicks (24 h)',
                ShortlinkClick::class,
                'shortlink-clicks',
                $today,
                $yesterday,
            ),
            $this->stat(
                'Article reads (24 h)',
                InternalArticleView::class,
                'internal-article-views',
                $today,
                $yesterday,
            ),
        ];
    }

    /**
     * @param  class-string  $modelClass
     */
    private function stat(string $label, string $modelClass, string $resourceSlug, Carbon $today, Carbon $yesterday): Stat
    {
        $now = (int) $modelClass::where('status', 'verified')
            ->where('created_at', '>=', $today)
            ->count();
        $prev = (int) $modelClass::where('status', 'verified')
            ->where('created_at', '>=', $yesterday)
            ->where('created_at', '<', $today)
            ->count();

        // Show absolute delta and direction so the operator doesn't have
        // to do mental math. % swings are noisy at low N (10 → 1 = -90%
        // looks scary but it's noise on a single-digit base).
        $delta = $now - $prev;
        $arrow = match (true) {
            $delta > 0 => 'heroicon-m-arrow-trending-up',
            $delta < 0 => 'heroicon-m-arrow-trending-down',
            default => 'heroicon-m-minus',
        };
        $color = match (true) {
            $delta > 0 => 'success',
            $delta < 0 && $prev >= 5 => 'warning', // small base → ignore swings
            default => 'gray',
        };
        $sign = $delta > 0 ? '+' : '';
        $description = "yesterday {$prev} ({$sign}{$delta})";

        return Stat::make($label, number_format($now))
            ->description($description)
            ->descriptionIcon($arrow)
            ->color($color)
            ->url(url('/admin/'.$resourceSlug.'?tableFilters[status][value]=verified'));
    }
}
