<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\UserIpObservation;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * At-a-glance metric for "is the platform under sock-puppet attack
 * right now?". Counts auth observations from the last 24 hours where
 * the IP has been used by 2+ distinct user_ids.
 *
 * Three cards:
 *   - Today's flagged observations (count) — rate of new shared-IP hits
 *   - Distinct shared IPs (last 24 h) — how many IPs are bleeding
 *   - Distinct affected users — sock-puppet population on those IPs
 *
 * All cards tap through to /admin/user-ip-observations with the
 * "Shared IPs only" ternary filter pre-applied.
 */
class SharedIpDetectionsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $since = now()->subDay();

        // Subquery: every IP that has 2+ distinct users (= "shared").
        // Joining the recent-observations set against this gives us the
        // set of (user, ip, observed-recently) rows that actually count.
        $sharedIps = DB::table('user_ip_observations')
            ->select('ip')
            ->groupBy('ip')
            ->havingRaw('count(distinct user_id) >= 2');

        $recentFlaggedRows = UserIpObservation::query()
            ->where('last_seen_at', '>=', $since)
            ->whereIn('ip', $sharedIps);

        $flaggedCount = (int) (clone $recentFlaggedRows)->count();
        $distinctSharedIps = (int) (clone $recentFlaggedRows)->distinct('ip')->count('ip');
        $distinctAffectedUsers = (int) (clone $recentFlaggedRows)->distinct('user_id')->count('user_id');

        // Tap-through link — Filament's TernaryFilter accepts ?tableFilters[shared_only][value]=true
        // to pre-apply the "Shared IPs only" filter on /admin/user-ip-observations.
        $sharedFilterUrl = url('/admin/user-ip-observations?tableFilters[shared_only][value]=true');

        return [
            Stat::make('Shared-IP hits (24 h)', (string) $flaggedCount)
                ->description($flaggedCount === 0 ? 'no sock-puppet activity' : 'auth observations on shared IPs')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color($flaggedCount === 0 ? 'success' : ($flaggedCount >= 50 ? 'danger' : 'warning'))
                ->url($sharedFilterUrl),

            Stat::make('Distinct shared IPs', (string) $distinctSharedIps)
                ->description('unique IPs flagged in the last 24 h')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color($distinctSharedIps === 0 ? 'gray' : 'warning')
                ->url($sharedFilterUrl),

            Stat::make('Distinct users on those IPs', (string) $distinctAffectedUsers)
                ->description('size of the sock-puppet pool')
                ->descriptionIcon('heroicon-m-users')
                ->color($distinctAffectedUsers === 0 ? 'gray' : 'danger')
                ->url($sharedFilterUrl),
        ];
    }
}
