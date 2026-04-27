<?php

namespace App\BotDetection\Signals;

use App\Models\CaptchaChallenge;
use App\Models\User;

/**
 * Penalises consistently fast captcha solves and PTC view starts.
 *
 * Humans show variance; scripts hit the deterministic minimum repeatedly.
 */
class ResponseTimeSignal implements Signal
{
    public function name(): string
    {
        return 'response_time';
    }

    public function evaluate(User $user): array
    {
        $solves = CaptchaChallenge::where('user_id', $user->id)
            ->whereNotNull('resolved_at')
            ->orderByDesc('resolved_at')
            ->limit(50)
            ->get(['issued_at', 'resolved_at']);

        if ($solves->isEmpty()) {
            return ['score' => 0.0, 'detail' => ['samples' => 0]];
        }

        $solveMs = [];
        foreach ($solves as $row) {
            // issued_at is non-nullable on the schema; resolved_at is filtered
            // via whereNotNull above. Raw timestamp arithmetic — Carbon 3's
            // diffInMilliseconds is signed and would go negative with this
            // argument order.
            if ($row->resolved_at !== null) {
                $solveMs[] = max(0, (int) ($row->resolved_at->getPreciseTimestamp(3) - $row->issued_at->getPreciseTimestamp(3)));
            }
        }
        if (empty($solveMs)) {
            return ['score' => 0.0, 'detail' => ['samples' => 0]];
        }

        $mean = array_sum($solveMs) / count($solveMs);
        $variance = 0.0;
        foreach ($solveMs as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $variance /= count($solveMs);
        $std = sqrt($variance);
        $cv = $mean > 0 ? $std / $mean : 0.0;

        // Bot signal: low coefficient of variation OR clustering near solve_ms_min boundary.
        $minBoundary = (int) config('satpeek.captcha.min_solve_ms', 800);
        $nearMin = count(array_filter($solveMs, fn ($v) => $v < $minBoundary * 1.2));
        $clusterRatio = $nearMin / count($solveMs);

        // Smooth combination — both criteria contribute.
        $cvScore = max(0.0, 1.0 - $cv * 4.0);
        $score = max($cvScore, min(1.0, $clusterRatio * 1.5));

        return [
            'score' => round($score, 3),
            'detail' => [
                'samples' => count($solveMs),
                'mean_ms' => (int) $mean,
                'cv' => round($cv, 3),
                'cluster_ratio' => round($clusterRatio, 3),
            ],
        ];
    }
}
