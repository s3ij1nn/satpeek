<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\AdvertiseController;
use App\Http\Controllers\Api\PtcController;
use App\Http\Controllers\Api\ShortlinkController;
use App\Http\Controllers\Webhook\BitcoTaskCallbackController;
use App\Models\BalanceLedger;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pays a referral commission out of the platform's 25 % ad-commission
 * pool when the referee earns a reward. The funding rule is the point —
 * documenting it here so the same invariant holds across every earnings
 * surface that calls this service:
 *
 *   - Advertiser pays `reward × (1 + ads.commission_pct/100)` for each
 *     view (see {@see AdvertiseController::computeCost}).
 *   - The `ads.commission_pct` slice is the platform's commission pool.
 *   - This service spends a portion of that pool on the referrer
 *     (`min(referral_pct, ads.commission_pct)` of the referee's reward),
 *     so the affiliate program NEVER reduces the referee's earnings AND
 *     never exceeds the platform's collected commission.
 *
 * For non-ad earnings (shortlinks, admin-managed PTC inventory, BitcoTask
 * postbacks), the platform's funding source is different but the same
 * cap applies — we never pay more than the configured maximum, and the
 * referee's reward is untouched. The cap exists to make the configurable
 * invariant `referral.commission_pct <= ads.commission_pct` enforceable
 * without yelling at the operator at boot.
 *
 * Wired into:
 *   - {@see PtcController}             — view complete
 *   - {@see ShortlinkController}       — click verified
 *   - {@see BitcoTaskCallbackController} — postback credit
 */
class ReferralPayout
{
    /**
     * Settle a referral commission for `$user`'s `$reward` if the user has
     * a referrer. Idempotent at the call-site level — the caller already
     * holds a uniqueness guarantee on `(reference_type, reference_id)` for
     * the referee credit, so re-invoking would just produce a duplicate
     * row. Caller is responsible for invoking this exactly once per
     * earning event.
     *
     * Returns the integer satoshi commission paid, or 0 when there's no
     * referrer / commission rounds to zero / the cap pinches it to zero.
     */
    public function settle(User $user, int $reward, string $refType, int $refId): int
    {
        if (! $user->referrer_id || $reward <= 0) {
            return 0;
        }

        $referralPct = (int) config('satpeek.referral.commission_pct', 10);
        $platformCapPct = (int) config('satpeek.ads.commission_pct', 25);
        // Cap the referral commission at the platform's commission pool —
        // for paid-ad earnings this enforces the funding invariant; for
        // other surfaces it's a defensive cap so a misconfigured
        // referral_pct can't bleed the operator dry.
        $effectivePct = min(max(0, $referralPct), max(0, $platformCapPct));
        if ($effectivePct <= 0) {
            return 0;
        }

        $commission = (int) floor($reward * $effectivePct / 100);
        if ($commission <= 0) {
            return 0;
        }

        DB::transaction(function () use ($user, $commission, $refType, $refId) {
            BalanceLedger::create([
                'user_id' => $user->referrer_id,
                'delta_sat' => $commission,
                'reason' => 'referral_commission',
                'reference_type' => $refType,
                'reference_id' => $refId,
            ]);
            DB::table('users')->where('id', $user->referrer_id)->increment('balance_sat', $commission);
            DB::table('users')->where('id', $user->referrer_id)->increment('total_earned_sat', $commission);
            Referral::query()
                ->where('referrer_id', $user->referrer_id)
                ->where('referred_id', $user->id)
                ->increment('lifetime_commission_sat', $commission);
        });

        return $commission;
    }
}
