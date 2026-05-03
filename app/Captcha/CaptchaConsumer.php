<?php

declare(strict_types=1);

namespace App\Captcha;

use App\Models\CaptchaChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Single-use atomic consumption of a previously-verified
 * `CaptchaChallenge` against an earn-complete event.
 *
 * Background: the existing `ChallengeVerifier::verify()` flow flips a
 * solved challenge to `status='verified'` so the frontend gets a
 * pass/fail signal. The earn-complete endpoints (PTC view, shortlink
 * click, internal article read) then receive the challenge_id alongside
 * the click/view token. Without this consumer, those endpoints would
 * accept ANY string and trust the frontend — a bot bypassing the
 * captcha widget can still claim the reward by POSTing an arbitrary
 * `captcha_challenge_id`.
 *
 * `consume()` enforces, atomically:
 *   1. The challenge id matches a real row
 *   2. status === 'verified' (i.e. the trace was actually validated)
 *   3. user_id matches the calling user (or is null on the anonymous
 *      auth path) — prevents a verified challenge from being shared
 *      across accounts
 *   4. The row hasn't already been consumed by another claim
 *
 * On success the row flips to status='consumed' so a replay of the
 * same challenge_id against another claim returns false. Done with
 * an atomic UPDATE WHERE status='verified' so two concurrent
 * /complete posts can't both consume the same row.
 */
class CaptchaConsumer
{
    /**
     * Returns true on a successful one-time consumption, false on any
     * mismatch / replay / unverified state. Callers MUST treat false
     * as "captcha not solved" and reject the claim.
     */
    public static function consume(?string $challengeId, ?User $user): bool
    {
        if (! is_string($challengeId) || $challengeId === '') {
            return false;
        }

        return (bool) DB::transaction(function () use ($challengeId, $user) {
            $row = CaptchaChallenge::query()
                ->where('challenge_id', $challengeId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return false;
            }
            if ($row->status !== 'verified') {
                return false;
            }
            // Bind to the user: a verified challenge issued anonymously
            // (login / register form) carries user_id=null and CAN be
            // consumed by the caller. A challenge issued during an
            // authenticated session MUST match.
            if ($row->user_id !== null && $user !== null && (int) $row->user_id !== (int) $user->id) {
                return false;
            }

            $consumed = CaptchaChallenge::query()
                ->where('challenge_id', $challengeId)
                ->where('status', 'verified')
                ->update(['status' => 'consumed']);

            return $consumed === 1;
        });
    }
}
