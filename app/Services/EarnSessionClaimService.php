<?php

declare(strict_types=1);

namespace App\Services;

use App\Captcha\CaptchaConsumer;
use App\Models\BalanceLedger;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Centralised credit pipeline for the three earning surfaces (PTC views,
 * shortlink clicks, internal article reads).
 *
 * Before this service the same ~70-line transaction lived three times — see
 * the v0.10.0 CHANGELOG entry. The pre-credit gates (token equality, captcha
 * consumption, elapsed-time floor), the atomic UPDATE-WHERE-pending claim,
 * the BalanceLedger row, the user balance bumps, and the referral payout
 * are now expressed once. Surface-specific behaviour rides through two
 * callbacks: {@see $preClaim} for extra gates that can reject the row
 * (e.g. PTC's heartbeat-deficit check), and {@see $postCredit} for extra
 * writes inside the credit transaction (e.g. PTC's ad-budget decrement).
 *
 * The atomic claim is the single most security-critical step on the
 * platform: it's the financial invariant that two concurrent /complete
 * posts cannot both pay out. The `UPDATE ... WHERE status = 'pending'`
 * filter is the primary guard; the `balance_ledgers` partial UNIQUE on
 * `(reason, reference_type, reference_id)` is the DB-layer backstop. Both
 * are enforced through this one path now, so a future earn surface can't
 * forget either guard by accident.
 */
class EarnSessionClaimService
{
    public function __construct(
        private readonly ReferralPayout $referralPayout,
    ) {}

    /**
     * Run the gates → atomic claim → balance writes pipeline.
     *
     * @param  Model  $session  Eloquent row carrying id, status, epoch_token, started_at columns. PtcView / ShortlinkClick / InternalArticleView all qualify.
     * @param  string  $providedToken  Request input — the token the client claims to hold.
     * @param  string  $captchaId  Request input — the captcha challenge to consume.
     * @param  string  $notPendingError  Error code returned when the session is not in `pending` state (e.g. `view_not_pending`).
     * @param  int  $minElapsedSeconds  Server-side floor on session duration. < this triggers `too_fast` rejection.
     * @param  string  $reason  BalanceLedger::REASON_* constant for the credit row.
     * @param  string  $referenceType  Model class string for the ledger reference_type column (typically `Model::class`).
     * @param  int  $rewardSat  Sat amount to credit.
     * @param  callable(): ?string|null  $preClaim  Optional gate run BEFORE the elapsed-time floor. Return a rejection_reason string to reject the row + return that code; null to pass.
     * @param  callable(): void|null  $postCredit  Optional hook called inside the credit transaction AFTER the ledger row + balance bumps + referral settle.
     */
    public function claim(
        Model $session,
        User $user,
        string $providedToken,
        string $captchaId,
        string $notPendingError,
        int $minElapsedSeconds,
        string $reason,
        string $referenceType,
        int $rewardSat,
        ?callable $preClaim = null,
        ?callable $postCredit = null,
    ): EarnSessionClaim {
        if ($session->getAttribute('status') !== 'pending') {
            return EarnSessionClaim::rejected($notPendingError);
        }
        if (! hash_equals((string) $session->getAttribute('epoch_token'), $providedToken)) {
            return EarnSessionClaim::rejected('token_mismatch');
        }
        if (! CaptchaConsumer::consume($captchaId, $user)) {
            return EarnSessionClaim::rejected('captcha_required');
        }

        if ($preClaim !== null) {
            $reject = $preClaim();
            if ($reject !== null) {
                $this->rejectRow($session, $reject);

                return EarnSessionClaim::rejected($reject);
            }
        }

        $startedAt = $session->getAttribute('started_at');
        $elapsed = $startedAt instanceof Carbon
            ? (int) abs($startedAt->diffInSeconds(Carbon::now()))
            : 0;
        if ($elapsed < $minElapsedSeconds) {
            $this->rejectRow($session, 'too_fast');

            return EarnSessionClaim::rejected('too_fast');
        }

        $credited = DB::transaction(function () use (
            $session,
            $user,
            $reason,
            $referenceType,
            $rewardSat,
            $postCredit,
        ): bool {
            // Atomic claim: only ONE concurrent request flips the row out of
            // pending. The next request sees affected_rows=0 and bails — so a
            // double-tap on the claim button or two parallel /complete posts
            // can't double-pay. The earlier non-pending guard up the stack
            // catches sequential replays cheaply; this is the race-condition
            // backstop for true concurrency.
            $claimed = $session->newQuery()
                ->whereKey($session->getKey())
                ->where('status', 'pending')
                ->update(['status' => 'verified', 'completed_at' => Carbon::now()]);
            if ($claimed === 0) {
                return false;
            }

            BalanceLedger::create([
                'user_id' => $user->id,
                'delta_sat' => $rewardSat,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $session->getKey(),
            ]);
            $user->increment('balance_sat', $rewardSat);
            $user->increment('total_earned_sat', $rewardSat);

            // Affiliate share — funded from the platform's commission pool,
            // never deducted from the viewer's reward. See ReferralPayout
            // for the funding invariant.
            $this->referralPayout->settle($user, $rewardSat, $referenceType, (int) $session->getKey());

            if ($postCredit !== null) {
                $postCredit();
            }

            return true;
        });

        if (! $credited) {
            return EarnSessionClaim::rejected($notPendingError);
        }

        return EarnSessionClaim::credited($rewardSat);
    }

    /**
     * Mark the session as rejected with the given reason. Used by the
     * pre-credit gate failures (heartbeat deficit, too_fast) — the row
     * captures *why* the claim failed for the operator triage surface.
     */
    private function rejectRow(Model $session, string $reason): void
    {
        $session->forceFill([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'completed_at' => Carbon::now(),
        ])->save();
    }
}
