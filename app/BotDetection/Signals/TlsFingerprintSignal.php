<?php

namespace App\BotDetection\Signals;

use App\Models\CaptchaChallenge;
use App\Models\User;

/**
 * Detects mismatch between claimed UA family and TLS JA4 hash.
 *
 * Real Chrome on macOS produces a stable JA4 family. If the JA4 changes
 * within a session, or if the JA4 is a known curl_cffi / OkHttp pattern
 * paired with a Chrome UA string, that's a hard tell.
 */
class TlsFingerprintSignal implements Signal
{
    private const SUSPICIOUS_JA4_PREFIXES = [
        't13d1715h1', // common curl_cffi default
        't13d191600', // requests urllib3 stale fingerprint
    ];

    public function name(): string
    {
        return 'tls_fingerprint';
    }

    public function evaluate(User $user): array
    {
        $rows = CaptchaChallenge::where('user_id', $user->id)
            ->whereNotNull('ja4')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['ja4', 'user_agent']);

        if ($rows->isEmpty()) {
            return ['score' => 0.0, 'detail' => ['samples' => 0]];
        }

        $unique = $rows->pluck('ja4')->unique()->count();
        $suspicious = 0;
        foreach ($rows as $row) {
            $ja4 = (string) $row->ja4;
            $ua = (string) $row->user_agent;
            foreach (self::SUSPICIOUS_JA4_PREFIXES as $bad) {
                if (str_starts_with($ja4, $bad)) {
                    $suspicious++;
                    break;
                }
            }
            // Chrome UA but JA4 doesn't start with t13.
            if (stripos($ua, 'chrome/') !== false && ! str_starts_with($ja4, 't13')) {
                $suspicious++;
            }
        }
        $ratio = $rows->count() > 0 ? $suspicious / $rows->count() : 0.0;

        $multiFp = $unique >= 3 ? 0.4 : 0.0;
        $score = min(1.0, $ratio + $multiFp);

        return [
            'score' => round($score, 3),
            'detail' => ['unique_ja4' => $unique, 'suspicious_pct' => round($ratio, 3)],
        ];
    }
}
