<?php

declare(strict_types=1);

namespace App\Deposit;

use App\Payout\Gateway\PayoutGateway;

/**
 * Polling-driven contract for detecting inbound crypto transfers to
 * SatPeek-controlled addresses.
 *
 * Distinct shape from {@see PayoutGateway} because
 * the deposit problem is fundamentally different from the payout
 * problem:
 *
 *   - **Trigger**: payouts are user-initiated (push); deposits are
 *     chain-initiated (pull) — we discover them by polling.
 *   - **Routing**: payouts know their target; deposits arrive at
 *     addresses and need to be matched to invoices afterwards.
 *   - **Lifecycle**: payouts are single-shot broadcast → confirm;
 *     deposits stream confirmations indefinitely (the same tx hash
 *     reappears in `scan()` each tick with a new confirmation count
 *     until it reaches finality).
 *
 * Implementations live per-chain:
 *   - `TronDepositObserver` (Phase 2b — TronGrid REST account
 *     transactions endpoint, address-keyed)
 *   - `EthDepositObserver` (Phase 3 — eth_getLogs Transfer events
 *     for ERC20, eth_getBalance polling for native ETH)
 *   - `BtcDepositObserver` (Phase 4 — Blockstream Esplora
 *     `/address/{addr}/txs`, since the public BTC node RPC has no
 *     address-indexed surface)
 *
 * Phase 2a: contract scaffold only. No implementations registered;
 * the cron that drives this is also out of scope until Phase 2b.
 */
interface DepositObserver
{
    /**
     * Stable identifier for the registry / audit log
     * (e.g. `tron`, `eth`, `btc`).
     */
    public function name(): string;

    /**
     * Scan for inbound transfers since the last poll.
     *
     * `$fromBlockHeight` is the chain height the previous tick
     * stopped at — implementations skip ahead from there so we
     * don't re-process the entire chain each tick. Pass 0 on the
     * first run; the caller persists `max(blockHeight)` of the
     * returned events for the next call.
     *
     * Returns an iterable so a per-chain implementation can stream
     * paginated results (TronGrid + Esplora both paginate at 200
     * txs / page) without buffering the whole set.
     *
     * Implementations MUST be idempotent: returning the same tx
     * twice across calls is expected (re-emit on each confirmation
     * bump). The caller dedupes on `(currency, txHash)`.
     *
     * @return iterable<int, DepositEvent>
     *
     * @throws DepositObserverException on transport failure (caller
     *                                  retries on next tick)
     */
    public function scan(int $fromBlockHeight): iterable;
}
