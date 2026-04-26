<?php

namespace App\BotDetection\Signals;

use App\Models\CaptchaChallenge;
use App\Models\User;

/**
 * Aggregates jerk-entropy values stored in captcha challenge meta.
 * Bots that submit synthetic curves cluster at low entropy across attempts.
 */
class TrajectoryEntropySignal implements Signal
{
    public function name(): string
    {
        return 'trajectory_entropy';
    }

    public function evaluate(User $user): array
    {
        $rows = CaptchaChallenge::where('user_id', $user->id)
            ->whereNotNull('meta')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['meta']);

        $entropies = [];
        foreach ($rows as $row) {
            $signals = ($row->meta['signals'] ?? []);
            if (isset($signals['jerk_entropy'])) {
                $entropies[] = (float) $signals['jerk_entropy'];
            }
        }
        if (count($entropies) < 3) {
            return ['score' => 0.0, 'detail' => ['samples' => count($entropies)]];
        }

        $mean = array_sum($entropies) / count($entropies);
        // Healthy human entropy hovers around 2.5–3.5 bits in our 16-bin histogram.
        // Anything < 1.5 mean across many trials is alarming.
        $score = $mean >= 2.5 ? 0.0
            : ($mean <= 0.8 ? 1.0 : (2.5 - $mean) / 1.7);

        return [
            'score' => round($score, 3),
            'detail' => ['samples' => count($entropies), 'mean_entropy' => round($mean, 3)],
        ];
    }
}
