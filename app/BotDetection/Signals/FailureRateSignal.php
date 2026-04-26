<?php

namespace App\BotDetection\Signals;

use App\Models\CaptchaChallenge;
use App\Models\User;
use Illuminate\Support\Carbon;

class FailureRateSignal implements Signal
{
    public function name(): string
    {
        return 'failure_rate';
    }

    public function evaluate(User $user): array
    {
        $window = Carbon::now()->subHour();
        $rows = CaptchaChallenge::where('user_id', $user->id)
            ->where('created_at', '>=', $window)
            ->whereIn('status', ['verified', 'rejected', 'expired'])
            ->get(['status']);

        $total = $rows->count();
        if ($total < 3) {
            return ['score' => 0.0, 'detail' => ['samples' => $total]];
        }

        $failed = $rows->whereIn('status', ['rejected', 'expired'])->count();
        $rate = $failed / $total;

        // Anything above 30% failure in 1h is suspicious; >70% is near-certain bot.
        $score = $rate <= 0.1 ? 0.0
            : ($rate >= 0.7 ? 1.0 : ($rate - 0.1) / 0.6);

        return [
            'score' => round($score, 3),
            'detail' => ['total' => $total, 'failed' => $failed, 'rate' => round($rate, 3)],
        ];
    }
}
