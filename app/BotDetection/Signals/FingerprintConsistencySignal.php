<?php

namespace App\BotDetection\Signals;

use App\Models\BehavioralEvent;
use App\Models\User;

class FingerprintConsistencySignal implements Signal
{
    public function name(): string
    {
        return 'fingerprint_consistency';
    }

    public function evaluate(User $user): array
    {
        $rows = BehavioralEvent::where('user_id', $user->id)
            ->where('kind', 'fp')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['payload']);

        $hashes = [];
        foreach ($rows as $row) {
            $h = $row->payload['hash'] ?? null;
            if (is_string($h) && $h !== '') {
                $hashes[$h] = ($hashes[$h] ?? 0) + 1;
            }
        }
        if (empty($hashes)) {
            return ['score' => 0.0, 'detail' => ['samples' => 0]];
        }

        $unique = count($hashes);
        $total = array_sum($hashes);
        // 1 fingerprint over many samples = trust. 5+ fingerprints = profile-hopping bot.
        $score = $unique <= 1 ? 0.0
            : ($unique >= 5 ? 1.0 : ($unique - 1) / 4.0);

        return [
            'score' => round($score, 3),
            'detail' => ['unique_fp' => $unique, 'samples' => $total],
        ];
    }
}
