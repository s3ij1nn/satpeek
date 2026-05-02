<?php

declare(strict_types=1);

namespace App\BotDetection\Signals;

use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;

/**
 * Detects "rapid extraction" patterns: a user requesting many withdrawals
 * in a tight window. Legit users withdraw infrequently (daily / weekly);
 * a fresh sock-puppet farming hard tries to drain a small balance often
 * before getting caught.
 *
 * Counts withdrawals (any status) created in the last `window_hours`
 * (default 24). The any-status choice is deliberate: a flurry of
 * `failed` or `hold` withdrawals is also signal — it means the operator
 * already mistrusts this account, AND the user keeps trying.
 *
 * Score: linear above `min_for_signal`, capped at `max_score`. The
 * defaults treat 3+ withdrawals/24h as bot-like (most legit users send
 * one weekly).
 */
class PayoutBurstSignal implements Signal
{
    public function name(): string
    {
        return 'payout_burst';
    }

    public function evaluate(User $user): array
    {
        $cfg = (array) config('satpeek.bot_score.payout_burst', []);
        $windowHours = (int) ($cfg['window_hours'] ?? 24);
        $minForSignal = (int) ($cfg['min_for_signal'] ?? 3);
        $scorePerExtra = (float) ($cfg['score_per_extra'] ?? 0.2);
        $maxScore = (float) ($cfg['max_score'] ?? 1.0);

        $since = Carbon::now()->subHours($windowHours);
        $count = (int) Withdrawal::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->count();

        if ($count < $minForSignal) {
            return [
                'score' => 0.0,
                'detail' => [
                    'reason' => 'no_burst',
                    'count_in_window' => $count,
                    'window_hours' => $windowHours,
                ],
            ];
        }

        // Score is per "extra" beyond the threshold so the floor stays at
        // 0 and a single-extra hit is mildly suspect rather than capped.
        $extra = max(0, $count - $minForSignal + 1);
        $score = min($maxScore, $extra * $scorePerExtra);

        return [
            'score' => $score,
            'detail' => [
                'reason' => 'payout_burst',
                'count_in_window' => $count,
                'window_hours' => $windowHours,
            ],
        ];
    }
}
