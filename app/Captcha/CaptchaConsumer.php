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
            // Strict user binding. The /complete endpoints that call this
            // consumer are all `auth` middleware-gated, so $user is never
            // null here in practice — but defence-in-depth requires the
            // row's user_id to match exactly. The previous "null on
            // either side passes" rule let an attacker solve a real
            // captcha at the login form (where ChallengeBuilder issues
            // user_id=null), capture the challenge_id from the verify
            // response, then POST it to /api/ptc/{view}/complete from a
            // separate authenticated session — one solve, one free
            // reward. Now the row user_id MUST be set AND must match.
            if ($user === null || $row->user_id === null || (int) $row->user_id !== (int) $user->id) {
                return false;
            }

            $consumed = CaptchaChallenge::query()
                ->where('challenge_id', $challengeId)
                ->where('status', 'verified')
                ->update(['status' => 'consumed']);

            return $consumed === 1;
        });
    }

    /**
     * Compensating action: revert a previously-consumed challenge back
     * to `verified` so the user can retry the same earn session.
     *
     * Why: `consume()` opens its own transaction (lockForUpdate) and
     * commits the status flip BEFORE the caller's outer credit
     * transaction begins. If that outer transaction fails (DB error,
     * unique-constraint on the ledger insert, gateway exception
     * mid-flight), the captcha row stays `consumed` permanently — the
     * user loses their solved captcha with nothing credited. Calling
     * `unconsume()` from the credit-failure branch puts the row back
     * to `verified` so the next /complete attempt from the same user
     * picks it up again.
     *
     * Atomicity: only flips rows where status currently equals
     * `consumed` AND the user_id still matches. A row that's already
     * been re-consumed by another path (extremely unlikely given the
     * outer transaction failure path is sequential) is left alone.
     *
     * Returns true if the unconsume actually fired (caller can audit-
     * log it), false if the row was no longer eligible to revert.
     */
    public static function unconsume(?string $challengeId, ?User $user): bool
    {
        if (! is_string($challengeId) || $challengeId === '' || $user === null) {
            return false;
        }

        $reverted = CaptchaChallenge::query()
            ->where('challenge_id', $challengeId)
            ->where('status', 'consumed')
            ->where('user_id', $user->id)
            ->update(['status' => 'verified']);

        return $reverted === 1;
    }
}
