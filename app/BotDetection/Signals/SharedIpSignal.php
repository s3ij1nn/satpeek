<?php

declare(strict_types=1);

namespace App\BotDetection\Signals;

use App\Models\User;
use App\Models\UserIpObservation;
use App\Services\UserIpObserver;

/**
 * Multi-account-by-IP signal.
 *
 * For each IP this user has authenticated from (rows in
 * `user_ip_observations` recorded by {@see UserIpObserver}),
 * count how many DISTINCT other users have used the same IP. The score
 * grows with the maximum cross-account count across the user's IP history,
 * normalised so a single shared IP is mildly suspicious (~0.3) while three
 * or more shared IPs is strongly bot-like (~1.0).
 *
 * The cap matters: shared NAT (university, mobile carrier, corporate WiFi,
 * household routers) legitimately puts dozens of unrelated users on the
 * same IP. Without the cap a single dorm room would push every new student
 * to "banned". The threshold (cross-account count → score) is tuned to
 * match the operator's policy in
 * `config('satpeek.bot_score.shared_ip')` — `min_others_for_signal`,
 * `score_per_other`, `max_score`. Defaults are deliberately conservative.
 */
class SharedIpSignal implements Signal
{
    public function name(): string
    {
        return 'shared_ip';
    }

    public function evaluate(User $user): array
    {
        $cfg = (array) config('satpeek.bot_score.shared_ip', []);
        $minOthers = (int) ($cfg['min_others_for_signal'] ?? 1);
        $scorePerOther = (float) ($cfg['score_per_other'] ?? 0.3);
        $maxScore = (float) ($cfg['max_score'] ?? 1.0);

        // Find every IP this user has been on, and for each, how many OTHER
        // distinct users have also been on it. The signal score is driven
        // by the worst (most cross-account) IP — a single sock-puppet IP
        // is enough; we don't average it down with a clean home IP.
        $ips = UserIpObservation::query()
            ->where('user_id', $user->id)
            ->pluck('ip')
            ->all();

        if ($ips === []) {
            return ['score' => 0.0, 'detail' => ['reason' => 'no_observations']];
        }

        $maxOthers = 0;
        $worstIp = null;
        foreach ($ips as $ip) {
            $others = (int) UserIpObservation::query()
                ->where('ip', $ip)
                ->where('user_id', '!=', $user->id)
                ->distinct()
                ->count('user_id');
            if ($others > $maxOthers) {
                $maxOthers = $others;
                $worstIp = (string) $ip;
            }
        }

        if ($maxOthers < $minOthers) {
            return [
                'score' => 0.0,
                'detail' => [
                    'reason' => 'no_shared_ip',
                    'ip_count' => count($ips),
                    'max_others' => $maxOthers,
                ],
            ];
        }

        $score = min($maxScore, $maxOthers * $scorePerOther);

        return [
            'score' => $score,
            'detail' => [
                'reason' => 'shared_ip',
                'worst_ip_others' => $maxOthers,
                'worst_ip' => $worstIp,
            ],
        ];
    }
}
