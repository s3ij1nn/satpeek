<?php

declare(strict_types=1);

namespace App\BotDetection\Signals;

use App\BotDetection\IpAllowlist;
use App\Models\User;
use App\Models\UserIpObservation;

/**
 * Detects sock-puppet farms registering many accounts from a single IP
 * inside a tight time window.
 *
 * SharedIpSignal already catches "this user authenticates from an IP
 * another account uses". That's a lifetime cross-account count and
 * fires regardless of when the other accounts joined. This signal is
 * narrower but higher-signal: focus on REGISTRATION events specifically
 * within a configurable window (default 24 h), so a real shared NAT
 * with users who joined years apart doesn't false-positive while a
 * fresh sock-puppet burst does.
 *
 * For each IP this user registered from, count DISTINCT other users
 * who also registered (source='register') from the same IP within
 * `window_hours` of this user's first observation on that IP. Score
 * scales with the worst (most concurrent registrations) IP.
 *
 * Allowlist support mirrors SharedIpSignal — operator-known shared
 * NATs (campus / mobile / corporate) get skipped via
 * `bot_score.shared_ip.allowlist` so we don't double-flag them.
 */
class RegistrationBurstSignal implements Signal
{
    public function name(): string
    {
        return 'registration_burst';
    }

    public function evaluate(User $user): array
    {
        $cfg = (array) config('satpeek.bot_score.registration_burst', []);
        $windowHours = (int) ($cfg['window_hours'] ?? 24);
        $minOthers = (int) ($cfg['min_others_for_signal'] ?? 2);
        $scorePerOther = (float) ($cfg['score_per_other'] ?? 0.25);
        $maxScore = (float) ($cfg['max_score'] ?? 1.0);

        // Reuse the SharedIpSignal allowlist so the operator manages one
        // list of trusted shared-NAT prefixes. Diverging the lists would
        // be a footgun the moment a campus IP shows up in one but not
        // the other.
        $allowRaw = (array) config('satpeek.bot_score.shared_ip.allowlist', []);
        $allowlist = self::loadAllowlist($allowRaw);

        // This user's register-IPs. We anchor the window to the row's
        // first_seen_at because that's when the registration actually
        // happened — last_seen_at would slide forward as the user logs
        // back in and the burst window would never expire.
        $rows = UserIpObservation::query()
            ->where('user_id', $user->id)
            ->where('source', 'register')
            ->get(['ip', 'first_seen_at']);

        if ($rows->isEmpty()) {
            return ['score' => 0.0, 'detail' => ['reason' => 'no_registration_observation']];
        }

        $maxOthers = 0;
        $worstIp = null;
        $allowlistedSkipped = 0;
        foreach ($rows as $row) {
            $ip = (string) $row->ip;
            if ($allowlist !== [] && IpAllowlist::matches($ip, $allowlist)) {
                $allowlistedSkipped++;

                continue;
            }
            $anchor = $row->first_seen_at;
            $windowStart = $anchor->copy()->subHours($windowHours);
            $windowEnd = $anchor->copy()->addHours($windowHours);

            $others = (int) UserIpObservation::query()
                ->where('ip', $ip)
                ->where('source', 'register')
                ->where('user_id', '!=', $user->id)
                ->whereBetween('first_seen_at', [$windowStart, $windowEnd])
                ->distinct()
                ->count('user_id');

            if ($others > $maxOthers) {
                $maxOthers = $others;
                $worstIp = $ip;
            }
        }

        if ($maxOthers < $minOthers) {
            return [
                'score' => 0.0,
                'detail' => [
                    'reason' => 'no_burst',
                    'max_others_in_window' => $maxOthers,
                    'window_hours' => $windowHours,
                    'allowlisted_skipped' => $allowlistedSkipped,
                ],
            ];
        }

        $score = min($maxScore, $maxOthers * $scorePerOther);

        return [
            'score' => $score,
            'detail' => [
                'reason' => 'registration_burst',
                'worst_ip_others' => $maxOthers,
                'worst_ip' => $worstIp,
                'window_hours' => $windowHours,
                'allowlisted_skipped' => $allowlistedSkipped,
            ],
        ];
    }

    /**
     * @param  mixed  $raw
     * @return array<int, string>
     */
    private static function loadAllowlist($raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($e): string => trim((string) $e),
            $raw,
        ), fn (string $e): bool => $e !== ''));
    }
}
