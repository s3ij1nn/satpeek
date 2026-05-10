<?php

declare(strict_types=1);

namespace App\Deposit;

/**
 * One observed inbound transfer to a SatPeek-controlled address.
 *
 * Returned from {@see DepositObserver::scan()}. Caller (the future
 * `WatchDepositsJob` cron) translates each event into either:
 *   - a brand-new `crypto_deposits` row (status=pending, awaiting
 *     confirmations), or
 *   - a confirmation update on an existing row (`confirmations_seen`
 *     bump, status promotion to `confirmed` once the per-currency
 *     finality threshold is reached).
 *
 * The amount is the smallest unit of the currency (sat for BTC,
 * wei for ETH, sun for TRX, atomic for USDT-TRC20). String-typed
 * because ETH wei × multi-BTC values overflow signed-64-bit;
 * the same precision rule that drives `Withdrawal.payout_amount`'s
 * decimal(36, 0) column.
 *
 * Phase 2a: contract scaffold only — no implementations yet.
 * Phase 2b+: per-chain `TronDepositObserver` + `EthDepositObserver`
 * + `BtcDepositObserver` (Esplora-backed for BTC).
 */
final class DepositEvent
{
    public function __construct(
        /** Currency code (BTC, ETH, USDT_TRC20, TRX, etc) — matches PayoutCurrency. */
        public readonly string $currency,
        /** Recipient address SatPeek owns; the operator's hot wallet or an HD-derived per-invoice address. */
        public readonly string $address,
        /** Amount as a decimal string in the currency's smallest unit. */
        public readonly string $amount,
        /** Chain-specific transaction id (txid for BTC, tx hash for ETH/TRX). */
        public readonly string $txHash,
        /** Confirmations seen at observation time; 0 means mempool / first-block. */
        public readonly int $confirmations,
        /** Block height the tx landed in; 0 if still in mempool. */
        public readonly int $blockHeight,
    ) {}
}
