<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\BotScore;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Operator-facing breakdown of the bot-detection score engine's verdicts
 * across all evaluated users. Surfaces shifts in the population
 * (e.g. a new attack wave that pushes `likely_bot` upward, or the
 * `banned` tier swelling after a fingerprint rotation).
 *
 * Single grouped query against `bot_scores`, indexed by `tier`. Numbers
 * include every row in the table (one per user, enforced by the unique
 * index on user_id) so they reflect lifetime population, not a rolling
 * window — easier to reason about than a 7-day cut.
 */
class BotTierDistributionWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $rows = BotScore::query()
            ->selectRaw('tier, count(*) as cnt')
            ->groupBy('tier')
            ->pluck('cnt', 'tier');

        $trust = (int) ($rows['trust'] ?? 0);
        $suspect = (int) ($rows['suspect'] ?? 0);
        $likelyBot = (int) ($rows['likely_bot'] ?? 0);
        $banned = (int) ($rows['banned'] ?? 0);

        return [
            Stat::make('Trust', (string) $trust)
                ->description('score < 0.30')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Suspect', (string) $suspect)
                ->description('0.30 ≤ score < 0.60')
                ->descriptionIcon('heroicon-m-question-mark-circle')
                ->color($suspect === 0 ? 'gray' : 'warning'),

            Stat::make('Likely bot', (string) $likelyBot)
                ->description('0.60 ≤ score < 0.85 — PTC blocked')
                ->descriptionIcon('heroicon-m-no-symbol')
                ->color($likelyBot === 0 ? 'gray' : 'danger'),

            Stat::make('Banned', (string) $banned)
                ->description('score ≥ 0.85 — full block')
                ->descriptionIcon('heroicon-m-shield-exclamation')
                ->color($banned === 0 ? 'gray' : 'danger'),
        ];
    }
}
