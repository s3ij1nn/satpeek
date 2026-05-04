<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\BalanceLedger;
use App\Models\User;
use App\Offerwall\AdapterRegistry;
use App\Services\ReferralPayout;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BitcoTasks server-to-server postback receiver.
 *
 * Per the spec (https://bitcotasks.com/documentations, fetched
 * 2026-04-27), the endpoint MUST respond with the literal lowercase
 * string "ok" within 60 s. Anything else (including a JSON body) is
 * treated as failure and re-sent. We return `ok` for every
 * successfully-received-and-attributed postback, including:
 *
 *   - genuine credit: ledger row inserted, user balance bumped
 *   - chargeback (status=2): negative ledger row inserted
 *   - duplicate transId: short-circuit, idempotent
 *   - debug=1 test postback: acknowledged with no balance change
 *   - unknown user (subId not in our DB): acknowledged so BitcoTasks
 *     stops retrying — the loss is on us for not finding the user
 *
 * Cases where we DON'T return `ok` (BitcoTasks should retry):
 *
 *   - signature mismatch / IP not whitelisted → 401
 *   - adapter not registered (config drift) → 503
 */
class BitcoTaskCallbackController extends Controller
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly ReferralPayout $referralPayout,
    ) {}

    public function __invoke(Request $request): Response
    {
        $adapter = $this->registry->get('bitcotask');
        if (! $adapter) {
            return response('adapter_not_registered', 503);
        }

        $result = $adapter->verifyCallback($request);
        if (! $result) {
            // Could be missing config, IP not whitelisted, or bad signature.
            // Log lives in the adapter; here we just refuse.
            return response('verification_failed', 401);
        }

        // debug=1 → BitcoTasks test postback. Ack but do nothing.
        if ((int) $request->input('debug', 0) === 1) {
            return response('ok');
        }

        // No subId we can resolve → ack so BitcoTasks stops retrying.
        if ($result->userId === null) {
            Log::warning('bitcotask postback without resolvable userId', [
                'transId' => $result->externalId,
            ]);

            return response('ok');
        }

        $user = User::find($result->userId);
        if (! $user) {
            Log::warning('bitcotask postback for unknown user', [
                'user_id' => $result->userId,
                'transId' => $result->externalId,
            ]);

            return response('ok');
        }

        // status=1 → credit, status=2 → chargeback. Anything else is
        // logged + acked but not credited; we don't want a silent
        // double-credit if BitcoTasks introduces a new status code we
        // haven't audited yet.
        if (! in_array($result->status, ['completed', 'chargeback'], true)) {
            Log::warning('bitcotask postback unrecognised status', [
                'transId' => $result->externalId,
                'status' => $result->status,
            ]);

            return response('ok');
        }

        $delta = $result->status === 'chargeback' ? -$result->rewardSat : $result->rewardSat;
        if ($delta === 0) {
            // Either reward was zero or the conversion rate is unset.
            // Don't write a noise row; ack and move on.
            return response('ok');
        }

        try {
            DB::transaction(function () use ($user, $result, $delta) {
                BalanceLedger::create([
                    'user_id' => $user->id,
                    'delta_sat' => $delta,
                    'reason' => BalanceLedger::REASON_BITCOTASK_POSTBACK,
                    // Idempotency key — composite UNIQUE on
                    // (reason, external_ref) makes a duplicate transId
                    // throw QueryException, caught below.
                    'external_ref' => $result->externalId,
                    'meta' => $result->meta,
                ]);
                if ($delta > 0) {
                    $user->increment('balance_sat', $delta);
                    $user->increment('total_earned_sat', $delta);
                    // Affiliate share — funded from the platform's
                    // commission pool, never deducted from the viewer's
                    // reward. transId is the idempotency key so the
                    // duplicate-postback short-circuit above also covers
                    // the affiliate side. ReferralPayout records its own
                    // ledger row keyed by reference_id = ledger row id.
                    $ledgerId = (int) BalanceLedger::query()
                        ->where('reason', BalanceLedger::REASON_BITCOTASK_POSTBACK)
                        ->where('external_ref', $result->externalId)
                        ->value('id');
                    if ($ledgerId > 0) {
                        $this->referralPayout->settle($user, $delta, BalanceLedger::class, $ledgerId);
                    }
                } else {
                    // Chargeback can drive balance negative if the user
                    // already withdrew; that's the operator's policy
                    // problem to surface, not ours to silently clamp.
                    $user->decrement('balance_sat', abs($delta));
                }
            });
        } catch (QueryException $e) {
            // Postgres + MySQL / sqlite signal unique-constraint violations
            // distinctly enough that we can rely on it. Anything else is a
            // real problem we want to surface.
            if (! self::isUniqueViolation($e)) {
                throw $e;
            }
            // Duplicate transId — already credited on a previous arrival.
            // Idempotency is the contract; ack so BitcoTasks stops retrying.
        }

        return response('ok');
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();

        // 23505 = postgres, 23000 = mysql / sqlite generic SQLSTATE class.
        return in_array($code, ['23000', '23505'], true);
    }
}
