<?php

namespace App\BotDetection\Signals;

use App\Models\PtcView;
use App\Models\User;

/**
 * For PTC views: if the client's beacons (heartbeats) arrive too perfectly
 * spaced (no jitter) or with too few samples for the duration, it suggests
 * a script polling on a fixed interval rather than a real browser tab.
 */
class HeartbeatGapSignal implements Signal
{
    public function name(): string
    {
        return 'heartbeat_gap';
    }

    public function evaluate(User $user): array
    {
        $views = PtcView::where('user_id', $user->id)
            ->whereNotNull('completed_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['heartbeats_received', 'heartbeats_expected', 'meta']);

        if ($views->isEmpty()) {
            return ['score' => 0.0, 'detail' => ['samples' => 0]];
        }

        $deficit = 0;
        $perfect = 0;
        foreach ($views as $v) {
            $expected = max(1, $v->heartbeats_expected);
            $received = $v->heartbeats_received;
            if ($received < (int) ($expected * 0.7)) {
                $deficit++;
            }
            $jitter = $v->meta['heartbeat_jitter_ms'] ?? null;
            if (is_numeric($jitter) && (float) $jitter < 50.0) {
                $perfect++;
            }
        }
        $deficitRate = $deficit / $views->count();
        $perfectRate = $perfect / $views->count();
        $score = min(1.0, $deficitRate * 0.7 + $perfectRate * 0.7);

        return [
            'score' => round($score, 3),
            'detail' => [
                'samples' => $views->count(),
                'deficit_rate' => round($deficitRate, 3),
                'perfect_rate' => round($perfectRate, 3),
            ],
        ];
    }
}
