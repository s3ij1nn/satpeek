<?php

namespace App\Offerwall;

use App\Models\User;
use App\Offerwall\Contracts\CallbackResult;
use App\Offerwall\Contracts\OfferDescriptor;
use App\Offerwall\Contracts\OfferwallAdapter;
use App\Offerwall\Contracts\ViewSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * BitcoTasks publisher integration.
 *
 * Per the BitcoTasks publisher docs (https://bitcotasks.com/documentations,
 * fetched 2026-04-27), there is NO REST publisher API for listing PTC /
 * shortlink offers. Publisher integration is offerwall-iframe-only:
 *
 *     <iframe src="https://bitcotasks.com/offerwall/[API_KEY]/[USER_ID]">
 *
 * The user completes offers inside the embedded wall; BitcoTasks credits
 * them via a server-to-server postback to a URL the operator sets in the
 * BitcoTasks dashboard.
 *
 * Postback contract (form-encoded HTTP POST, lowercase form fields):
 *
 *     subId         publisher's user identifier (the SubId we passed in
 *                   the iframe URL)
 *     transId       BitcoTasks transaction ID — IDEMPOTENCY KEY
 *     offer_name    human-readable offer name
 *     offer_type    "ptc" | "offer" | "task" | "shortlink"
 *     reward        the publisher-side reward amount (decimal string)
 *     reward_name   "Points" / etc — operator-defined unit
 *     reward_value  reward amount in operator unit (decimal string)
 *     payout        operator's USD cost (decimal string)
 *     userIp        end-user IP at the moment they completed the offer
 *     country       2-letter country code
 *     status        1 = credit, 2 = chargeback (subtract)
 *     debug         1 = test postback (no real reward), 0 = live
 *     signature     md5(subId . transId . reward . s2s_secret)
 *
 * Verification:
 *   - signature MUST match md5(subId.transId.reward.secret) byte-for-byte.
 *   - source IP SHOULD match the BitcoTasks-published whitelist
 *     (45.14.135.48 at time of writing). The whitelist is config-driven
 *     so an operator can adjust without a code change.
 *
 * Response (handled by the controller, not this class):
 *   - The endpoint MUST respond with the literal string `ok` (lowercase,
 *     no whitespace, no JSON). BitcoTasks treats anything else as failure
 *     and may retry. 60-second timeout.
 *
 * Idempotency:
 *   - The handler stores `transId` in `balance_ledgers.external_ref`
 *     under `reason='bitcotask_postback'`. A unique index on
 *     (reason, external_ref) makes the second arrival a no-op.
 */
class BitcoTaskAdapter implements OfferwallAdapter
{
    public function name(): string
    {
        return 'bitcotask';
    }

    /**
     * BitcoTasks doesn't expose a REST list-offers endpoint — offers are
     * presented inside the offerwall iframe at runtime. We return an empty
     * descriptor list so the SyncOfferwallsCommand cron is a safe no-op
     * even when 'bitcotask' is in OFFERWALLS_ENABLED.
     *
     * @return array<int, OfferDescriptor>
     */
    public function fetchPtcOffers(): array
    {
        return [];
    }

    /** @return array<int, OfferDescriptor> */
    public function fetchShortlinkOffers(): array
    {
        return [];
    }

    public function startView(User $user, OfferDescriptor $offer): ViewSession
    {
        // Same reasoning as fetch*Offers — there is no per-offer start
        // endpoint. The iframe handles the entire watch / hold / claim
        // flow internally and reports completion via postback. Throw so
        // the call site notices it's a no-op rather than silently
        // returning a half-built session.
        throw new LogicException(
            'BitcoTask offers run inside the offerwall iframe; '
            .'there is no startView endpoint. Embed the iframe at '
            .'https://bitcotasks.com/offerwall/<API_KEY>/<USER_ID> instead.'
        );
    }

    public function verifyCallback(Request $request): ?CallbackResult
    {
        $secret = (string) config('satpeek.bitcotask.s2s_secret');
        if ($secret === '') {
            return null;
        }

        // IP allow-list — a defence-in-depth signal alongside the MD5
        // signature. BitcoTasks publishes their postback IP and updates
        // it rarely; operator-overridable via env when it changes.
        $allowed = (array) config('satpeek.bitcotask.ip_allowlist', []);
        if ($allowed !== [] && ! in_array($request->ip(), $allowed, true)) {
            Log::warning('bitcotask postback from non-whitelisted IP', [
                'ip' => $request->ip(),
            ]);

            return null;
        }

        $subId = (string) $request->input('subId', '');
        $transId = (string) $request->input('transId', '');
        $reward = (string) $request->input('reward', '');
        $signature = (string) $request->input('signature', '');

        if ($subId === '' || $transId === '' || $reward === '' || $signature === '') {
            return null;
        }

        // Spec: md5($subId . $transId . $reward . $secretKey).
        $expected = md5($subId.$transId.$reward.$secret);
        if (! hash_equals($expected, strtolower($signature))) {
            Log::warning('bitcotask postback signature mismatch', [
                'transId' => $transId,
                'subId' => $subId,
            ]);

            return null;
        }

        // BitcoTasks reports `payout` in USD (decimal). The operator
        // configures a USD→sat conversion rate so the credit lands in
        // the same unit as the rest of the platform.
        $payoutUsd = (float) $request->input('payout', 0);
        $usdToSat = (float) config('satpeek.bitcotask.usd_to_sat', 0);
        $rewardSat = $usdToSat > 0 ? (int) round($payoutUsd * $usdToSat) : 0;

        $statusCode = (int) $request->input('status', 0);
        $userId = ctype_digit($subId) ? (int) $subId : null;

        return new CallbackResult(
            source: $this->name(),
            // externalId carries transId so the controller can use it as
            // the idempotency key (balance_ledgers.external_ref).
            externalId: $transId,
            userId: $userId,
            rewardSat: $rewardSat,
            // status=1 → credit, status=2 → chargeback. Anything else is
            // surfaced verbatim so a future BitcoTasks status doesn't get
            // silently dropped.
            status: match ($statusCode) {
                1 => 'completed',
                2 => 'chargeback',
                default => 'unknown_status_'.$statusCode,
            },
            meta: $request->all(),
        );
    }
}
