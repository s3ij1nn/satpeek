<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BotScoreHistory;
use App\Models\InternalArticleView;
use App\Models\PtcView;
use App\Models\ShortlinkClick;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;

/**
 * Aggregates the past-week activity buckets that go into the operator
 * weekly summary email. Pure builder — returns a plain array so the
 * mailable view stays a presentation layer and tests can assert
 * against the structured payload directly.
 *
 * Window semantics: "this week" = the trailing 7 days from `now()`,
 * "previous week" = the 7 days before that. Calendar-week alignment
 * was tempting but trips on operator timezone — a trailing 7-day
 * window is correct in every timezone the operator might read mail in.
 */
class WeeklySummaryBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(?Carbon $reference = null): array
    {
        $reference ??= Carbon::now();
        $thisStart = $reference->copy()->subDays(7);
        $prevStart = $reference->copy()->subDays(14);

        return [
            'window' => [
                'this_start' => $thisStart->toIso8601String(),
                'this_end' => $reference->toIso8601String(),
                'previous_start' => $prevStart->toIso8601String(),
                'previous_end' => $thisStart->toIso8601String(),
            ],
            'earning' => [
                'ptc_views' => $this->verifiedDelta(PtcView::class, $thisStart, $prevStart),
                'shortlink_clicks' => $this->verifiedDelta(ShortlinkClick::class, $thisStart, $prevStart),
                'article_reads' => $this->verifiedDelta(InternalArticleView::class, $thisStart, $prevStart),
            ],
            'payouts' => $this->payoutBuckets($thisStart, $reference),
            'users' => [
                'new_this_week' => (int) User::query()
                    ->where('created_at', '>=', $thisStart)
                    ->where('created_at', '<', $reference)
                    ->count(),
                'new_previous_week' => (int) User::query()
                    ->where('created_at', '>=', $prevStart)
                    ->where('created_at', '<', $thisStart)
                    ->count(),
            ],
            'tier_transitions' => $this->tierTransitions($thisStart, $reference),
        ];
    }

    /**
     * Return current vs prior 7-day verified counts plus signed delta.
     *
     * @param  class-string  $modelClass
     * @return array{this: int, previous: int, delta: int}
     */
    private function verifiedDelta(string $modelClass, Carbon $thisStart, Carbon $prevStart): array
    {
        $now = (int) $modelClass::where('status', 'verified')
            ->where('created_at', '>=', $thisStart)
            ->count();
        $prev = (int) $modelClass::where('status', 'verified')
            ->where('created_at', '>=', $prevStart)
            ->where('created_at', '<', $thisStart)
            ->count();

        return ['this' => $now, 'previous' => $prev, 'delta' => $now - $prev];
    }

    /**
     * Withdrawals: by-status counts + total successful payout sat
     * shipped. `failed` excludes ad-funding refunds; we only count
     * rows whose original status was `queued/processing` and ended
     * at `failed`. Approximated here as any failed withdraw row in
     * the window.
     *
     * @return array{sent_count: int, sent_total_sat: int, failed_count: int, hold_count: int}
     */
    private function payoutBuckets(Carbon $thisStart, Carbon $end): array
    {
        $sent = Withdrawal::query()
            ->where('status', 'sent')
            ->where('created_at', '>=', $thisStart)
            ->where('created_at', '<', $end)
            ->selectRaw('count(*) as cnt, coalesce(sum(amount_sat), 0) as total_sat')
            ->first();

        return [
            'sent_count' => (int) ($sent->cnt ?? 0),
            'sent_total_sat' => (int) ($sent->total_sat ?? 0),
            'failed_count' => (int) Withdrawal::query()
                ->where('status', 'failed')
                ->where('created_at', '>=', $thisStart)
                ->where('created_at', '<', $end)
                ->count(),
            'hold_count' => (int) Withdrawal::query()
                ->where('status', 'hold')
                ->where('created_at', '>=', $thisStart)
                ->where('created_at', '<', $end)
                ->count(),
        ];
    }

    /**
     * Counts evaluations that LANDED in each non-trust tier this week.
     * NOT a count of unique users (a user oscillating between tiers
     * gets multiple rows here) — that's intentional: it surfaces
     * enforcement velocity rather than a population snapshot, which
     * BotTierDistributionWidget already covers.
     *
     * @return array{suspect: int, likely_bot: int, banned: int}
     */
    private function tierTransitions(Carbon $thisStart, Carbon $end): array
    {
        $rows = BotScoreHistory::query()
            ->where('created_at', '>=', $thisStart)
            ->where('created_at', '<', $end)
            ->whereIn('tier', ['suspect', 'likely_bot', 'banned'])
            ->selectRaw('tier, count(*) as cnt')
            ->groupBy('tier')
            ->pluck('cnt', 'tier');

        return [
            'suspect' => (int) ($rows['suspect'] ?? 0),
            'likely_bot' => (int) ($rows['likely_bot'] ?? 0),
            'banned' => (int) ($rows['banned'] ?? 0),
        ];
    }
}
