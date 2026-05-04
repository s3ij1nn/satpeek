<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CaptchaChallenge;
use App\Models\InternalArticle;
use App\Models\PtcAd;
use App\Models\ShortlinkProviderCredential;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Anonymous-visible platform stats for the public landing page.
 *
 * Three trust signals visitors actually act on:
 *   - total sat shipped via FaucetPay (lifetime; "the platform pays")
 *   - active earning inventory right now (PTC ads + shortlink providers
 *     + internal articles; "there's something to do")
 *   - bot-rejection rate over the last 30 days (validates the
 *     "captcha bots can't pass" pitch with a real number)
 *
 * Cached for 10 minutes — these numbers don't move fast and the
 * landing page is a hot path. The cache key shards by month so an
 * operator pruning old withdrawal rows doesn't see the cached value
 * lag forever.
 */
class PublicStatsBuilder
{
    /**
     * @return array{total_sat_paid: int, active_inventory: int, bot_rejection_rate: float, captcha_attempts_30d: int}
     */
    public function build(): array
    {
        return Cache::remember(
            'public_stats:'.Carbon::now()->format('Y-m'),
            now()->addMinutes(10),
            fn (): array => $this->compute(),
        );
    }

    /**
     * @return array{total_sat_paid: int, active_inventory: int, bot_rejection_rate: float, captcha_attempts_30d: int}
     */
    private function compute(): array
    {
        $totalSatPaid = (int) Withdrawal::query()
            ->where('status', 'sent')
            ->sum('amount_sat');

        $activeInventory = (int) PtcAd::query()
            ->where('is_active', true)
            ->where('status', 'approved')
            ->count()
            + (int) ShortlinkProviderCredential::query()
                ->where('is_active', true)
                ->whereNotNull('api_token')
                ->count()
            + (int) InternalArticle::query()
                ->where('is_active', true)
                ->count();

        // Bot-rejection rate: rejected vs (verified + rejected) over
        // the last 30 days. Excludes `issued` (still in flight) and
        // `expired` (user closed the tab) so the denominator is just
        // resolved attempts. Returns 0.0 on a brand-new install with
        // no resolved captchas yet (avoids a NaN that would render as
        // "%" with no number).
        $since = Carbon::now()->subDays(30);
        $resolved = CaptchaChallenge::query()
            ->where('created_at', '>=', $since)
            ->whereIn('status', ['verified', 'rejected', 'consumed'])
            ->selectRaw("sum(case when status = 'rejected' then 1 else 0 end) as rejected,
                         count(*) as total")
            ->first();

        $rejected = (int) ($resolved->rejected ?? 0);
        $total = (int) ($resolved->total ?? 0);
        $rejectionRate = $total > 0 ? round($rejected / $total, 3) : 0.0;

        return [
            'total_sat_paid' => $totalSatPaid,
            'active_inventory' => $activeInventory,
            'bot_rejection_rate' => $rejectionRate,
            'captcha_attempts_30d' => $total,
        ];
    }
}
