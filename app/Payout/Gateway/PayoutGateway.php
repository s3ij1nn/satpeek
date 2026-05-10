<?php

declare(strict_types=1);

namespace App\Payout\Gateway;

use App\Models\Withdrawal;

/**
 * Common contract for the payout-routing strategies.
 *
 * `App\Payout\ProcessWithdrawalJob` looks at `Withdrawal.payout_method`
 * and asks the registry for the matching gateway. Each gateway is
 * responsible for moving the user's funds via its specific transport
 * (FaucetPay HTTP API, Bitcoin Core RPC, Tron HTTP, ETH RPC, …) and
 * stamping the result back onto the row.
 *
 * Why a thin interface? The transports differ enough that a fat
 * abstract base would just leak FaucetPay-isms onto the onchain
 * gateways. Keep the contract minimal:
 *
 *   - `name()` is used by the dispatcher / audit logs.
 *   - `send()` returns a {@see PayoutResult} the job persists; the
 *     gateway's internal failure modes (HTTP, RPC, transient vs
 *     terminal) are folded into the result shape so the job has one
 *     code path.
 *
 * Throwing instead of returning a result is reserved for the
 * "broadcast never reached the wire" case — those exceptions fall
 * out to the job's retry machinery. ProcessWithdrawalJob is gateway-
 * agnostic about which exception class signals "retryable"; each
 * implementation defines its own marker subclass (e.g.
 * `FaucetPayUnreachableException`).
 */
interface PayoutGateway
{
    /**
     * Stable identifier matching the values stored in
     * `Withdrawal.payout_method`. Examples: `faucetpay`, `onchain_btc`,
     * `onchain_eth`, `onchain_trx`, `onchain_usdt_trc20`.
     */
    public function name(): string;

    /**
     * Send the payout. Returns a result the job uses to settle the row.
     *
     * Implementations MUST distinguish:
     *   - reach failure (TCP/DNS pre-flight): throw a transport-specific
     *     "unreachable" exception so the job retries safely.
     *   - all other failures (HTTP error, contract revert, body
     *     parse fail): return PayoutResult::failed() — the job
     *     marks `failed` + refunds the user, never retries.
     */
    public function send(Withdrawal $withdrawal): PayoutResult;
}
