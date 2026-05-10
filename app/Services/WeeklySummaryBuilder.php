<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WithdrawalStatus;
use App\Models\BotScoreHistory;
use App\Models\InternalArticleView;
use App\Models\PtcView;
use App\Models\ShortlinkClick;
use App\Models\User;
use App\Models\Withdrawal;
use App\Payout\WalletBalanceMonitorRegistry;
use App\Payout\WalletBalanceUnavailableException;
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
            'hot_wallet' => $this->hotWalletSnapshot(),
        ];
    }

    /**
     * Per-currency hot-wallet runway at the moment the digest builds.
     * Same shape the `/up` probe + dashboard widget use, denormalised
     * for the email template. Empty array for FaucetPay-only deploys.
     *
     * @return array<int, array<string, mixed>>
     */
    private function hotWalletSnapshot(): array
    {
        $registry = app(WalletBalanceMonitorRegistry::class);
        $monitors = $registry->all();
        $rows = [];
        foreach ($monitors as $monitor) {
            $code = $monitor->currency();
            try {
                $available = $monitor->available();
            } catch (WalletBalanceUnavailableException) {
                $rows[] = [
                    'code' => $code,
                    'status' => 'unavailable',
                    'available' => null,
                    'required' => null,
                    'gap' => null,
                ];

                continue;
            }
            $required = $monitor->required();
            $gap = bcsub($available, $required, 0);
            $rows[] = [
                'code' => $code,
                'status' => $this->runwayStatus($available, $required, $gap),
                'available' => $available,
                'required' => $required,
                'gap' => $gap,
            ];
        }

        return $rows;
    }

    private function runwayStatus(string $available, string $required, string $gap): string
    {
        if (bccomp($gap, '0', 0) < 0) {
            return 'down';
        }
        if (bccomp($required, '0', 0) > 0 && bccomp($gap, $required, 0) < 0) {
            return 'degraded';
        }

        return 'ok';
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
     * Single GROUP BY status, sum(amount_sat) — sum is meaningful only
     * for the `sent` row but cheap enough to compute everywhere.
     *
     * @return array{sent_count: int, sent_total_sat: int, failed_count: int, hold_count: int}
     */
    private function payoutBuckets(Carbon $thisStart, Carbon $end): array
    {
        $rows = Withdrawal::query()
            ->whereIn('status', ['sent', 'failed', 'hold'])
            ->where('created_at', '>=', $thisStart)
            ->where('created_at', '<', $end)
            ->selectRaw('status, count(*) as cnt, coalesce(sum(amount_sat), 0) as total_sat')
            ->groupBy('status')
            ->get()
            // status is now a backed-enum cast (WithdrawalStatus) on
            // hydrated Withdrawal rows. The selectRaw above returns
            // the raw column value through the Eloquent cast pipeline,
            // so $r->getAttribute('status') is a WithdrawalStatus
            // instance — `->value` to get the string back for keyBy.
            ->keyBy(fn ($r): string => $r->getAttribute('status') instanceof WithdrawalStatus
                ? $r->getAttribute('status')->value
                : (string) $r->getAttribute('status'));

        return [
            'sent_count' => (int) ($rows->get('sent')?->getAttribute('cnt') ?? 0),
            'sent_total_sat' => (int) ($rows->get('sent')?->getAttribute('total_sat') ?? 0),
            'failed_count' => (int) ($rows->get('failed')?->getAttribute('cnt') ?? 0),
            'hold_count' => (int) ($rows->get('hold')?->getAttribute('cnt') ?? 0),
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
