<?php

declare(strict_types=1);

namespace App\Payout;

/**
 * Per-currency static metadata for the multi-currency payout flow.
 *
 * Currency codes are SatPeek-internal — `USDT_TRC20` rather than just
 * `USDT` so a future `USDT_ERC20` or `USDT_BEP20` sits alongside cleanly
 * without ambiguity. The {@see $faucetpayCode} maps to FaucetPay's own
 * shorter code (USDT-TRC20 is just `USDTTRC` on their side, etc.).
 *
 * `decimals` is the number of base-10 fractional digits below the
 * "main" unit — 8 for BTC (sats), 18 for ETH (wei), 6 for USDT-TRC20.
 * Used by PriceOracle to convert between BTC sats (the platform's
 * accounting unit) and the on-the-wire amount the gateway sends.
 *
 * `minWithdrawSat` is per-currency: a 1000-BTC-sat ETH withdraw is
 * silly because gas fees alone exceed the value, so each chain has its
 * own floor expressed in BTC sats (the user balance unit) for ledger
 * consistency.
 *
 * `faucetpaySupported` / `onchainSupported` flags are read by the
 * UI + WithdrawController to filter the currency picker AND by the
 * gateway dispatcher to refuse a route that's not yet wired
 * (`onchainSupported = false` until the per-chain gateway lands).
 */
final class PayoutCurrency
{
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $faucetpayCode,
        public readonly int $decimals,
        public readonly int $minWithdrawSat,
        public readonly bool $faucetpaySupported,
        public readonly bool $onchainSupported,
        public readonly string $coingeckoId,
    ) {}
}
