<?php

declare(strict_types=1);

namespace App\Services;

use App\BotDetection\ScoreEngine;
use App\Models\User;
use App\Models\UserIpObservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records the IP a user authenticated from. Called at login submit /
 * registration submit / email verification / password reset — the four
 * moments where SatPeek can confidently say "this human-controlled
 * action came from this IP".
 *
 * Two outputs:
 *
 *   1. Append-or-update a row in `user_ip_observations` so we can ask
 *      "which IPs has this user been on?" and "which users have been
 *      on this IP?".
 *   2. When the same IP appears under a DIFFERENT user_id, surface the
 *      cross-user count to the caller. The auth controller can decide
 *      what to do with it (raise the bot score, send the operator a
 *      heads-up, lock the account for review).
 *
 * Cookie-only multi-account dedup misses the operator who clears
 * cookies / opens an incognito window. IP-only dedup misses the
 * operator who hops to mobile data. Together they catch the common
 * cases without the false positives of either alone.
 */
class UserIpObserver
{
    /**
     * Record a single (user, ip, source) observation and return how many
     * OTHER users have been on the same IP. Returns 0 when the IP is
     * fresh, garbage, or unparseable.
     */
    public function record(User $user, ?string $ip, string $source = 'login'): int
    {
        if ($ip === null || $ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return 0;
        }

        $now = Carbon::now();

        DB::transaction(function () use ($user, $ip, $source, $now) {
            $existing = UserIpObservation::query()
                ->where('user_id', $user->id)
                ->where('ip', $ip)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->update([
                    'last_seen_at' => $now,
                    'hit_count' => (int) $existing->hit_count + 1,
                    // `source` reflects the most recent context — useful
                    // when a user usually logs in but the latest hit is
                    // a password reset, etc.
                    'source' => $source,
                ]);
            } else {
                UserIpObservation::create([
                    'user_id' => $user->id,
                    'ip' => $ip,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'hit_count' => 1,
                    'source' => $source,
                ]);
            }
        });

        $otherUsers = (int) UserIpObservation::query()
            ->where('ip', $ip)
            ->where('user_id', '!=', $user->id)
            ->distinct()
            ->count('user_id');

        if ($otherUsers > 0) {
            // Operator-visible signal. Logged at warning so default log
            // routing surfaces it to dashboards without us also having
            // to wire a notifier or DB events table for the first cut.
            Log::warning('shared_ip_multi_account', [
                'user_id' => $user->id,
                'ip' => $ip,
                'source' => $source,
                'other_user_count' => $otherUsers,
            ]);
        }

        // Re-evaluate the bot score so SharedIpSignal (and any other
        // signal whose inputs may have shifted at this auth event) updates
        // the user's tier without waiting for the next captcha verify.
        // Throttled — multiple auth events in quick succession only burn
        // one signal sweep. Defensive try/catch so a scoring failure
        // (DB blip, signal exception) never breaks the auth flow itself.
        try {
            app(ScoreEngine::class)->evaluateThrottled($user);
        } catch (Throwable $e) {
            Log::warning('bot score re-eval failed at ip observation', [
                'user_id' => $user->id,
                'err' => $e->getMessage(),
            ]);
        }

        return $otherUsers;
    }
}
