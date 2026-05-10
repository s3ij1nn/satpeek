<?php

declare(strict_types=1);

namespace App\Payout;

/**
 * Operator-facing hot-wallet liquidity probe.
 *
 * Returns "available vs required" for a given payout currency so the
 * Filament dashboard widget + the operator notification channel can
 * surface "hot wallet about to run dry" before the queue stalls.
 *
 * `available` is the chain-side balance the wallet can spend right
 * now (BTC sats, ETH wei, USDT-TRC20 atomic). `required` is the
 * sat-equivalent of in-flight withdrawals queued against this
 * currency — so the operator can see "wallet has 3 ETH but pending
 * payouts total 4 ETH" before users start failing.
 *
 * Why a contract instead of a single concrete service: per-chain
 * balance lookups speak to different RPCs (FaucetPay's
 * `/api/v1/balance`, TronGrid `/wallet/getaccount`, ETH
 * `eth_getBalance`, BTC node `getbalance`). Each implementation
 * owns its transport; the registry-style consumer (Filament widget
 * + `/up` extension + alerting cron) sees a uniform API.
 *
 * Phase 2a: contract scaffold only. Per-chain implementations land
 * with their gateway in Phase 2b+.
 *
 * Both return values are decimal strings (smallest-unit) for the
 * same precision reason as `Withdrawal.payout_amount` — ETH wei ×
 * multi-BTC values overflow signed-64-bit.
 */
interface WalletBalanceMonitor
{
    /**
     * SatPeek-internal currency code this monitor reports on
     * (BTC, ETH, USDT_TRC20, TRX, etc — matches PayoutCurrency).
     */
    public function currency(): string;

    /**
     * Wallet's spendable balance NOW, in the currency's smallest unit.
     *
     * @throws WalletBalanceUnavailableException on transport failure
     */
    public function available(): string;

    /**
     * Sum of `payout_amount` for in-flight withdrawals against this
     * currency (status in queued / processing / hold / broadcast).
     * The reading is "what's owed but not yet settled" — the gap
     * between this and `available()` is the topup runway.
     */
    public function required(): string;
}
