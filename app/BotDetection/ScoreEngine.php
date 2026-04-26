<?php

namespace App\BotDetection;

use App\BotDetection\Signals\Signal;
use App\Models\BotScore;
use App\Models\User;
use Illuminate\Support\Carbon;

class ScoreEngine
{
    /**
     * @param  array<int, Signal>  $signals
     */
    public function __construct(private readonly array $signals) {}

    public function evaluate(User $user): BotScore
    {
        $weights = config('satpeek.bot_score.weights', []);
        $weightSum = array_sum(array_map(fn ($w) => (float) $w, $weights));
        if ($weightSum <= 0) {
            $weightSum = 1.0;
        }

        $score = 0.0;
        $detail = [];
        foreach ($this->signals as $signal) {
            $name = $signal->name();
            $weight = (float) ($weights[$name] ?? 0.0);
            $result = $signal->evaluate($user);
            $detail[$name] = [
                'weight' => $weight,
                'raw' => $result['score'],
                'detail' => $result['detail'],
            ];
            $score += $result['score'] * $weight;
        }
        $score = $score / $weightSum;

        $tier = self::scoreToTier($score);

        $row = BotScore::updateOrCreate(
            ['user_id' => $user->id],
            [
                'score' => $score,
                'tier' => $tier,
                'signals' => $detail,
                'last_evaluated_at' => Carbon::now(),
            ]
        );

        if ($tier === 'banned') {
            // Bypass mass-assignment guard: is_banned must remain admin-only via fillable.
            $user->forceFill(['is_banned' => true, 'ban_reason' => 'bot_score'])->save();
        }

        return $row;
    }

    public static function scoreToTier(float $score): string
    {
        $cfg = config('satpeek.bot_score');
        if ($score >= ($cfg['ban'] ?? 0.85)) {
            return 'banned';
        }
        if ($score >= ($cfg['likely_bot'] ?? 0.60)) {
            return 'likely_bot';
        }
        if ($score >= ($cfg['suspect'] ?? 0.30)) {
            return 'suspect';
        }
        return 'trust';
    }
}
