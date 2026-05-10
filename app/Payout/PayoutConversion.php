<?php

declare(strict_types=1);

namespace App\Payout;

/**
 * Result of {@see PriceOracle::convertBtcSatToTarget()}.
 *
 * Replaces the previous `array{0: string, 1: string}` tuple return.
 * The two strings carry semantically distinct values:
 *
 *   - `targetAmount` — count of the target currency's smallest unit
 *     (wei for ETH, sun for TRX, USDT-TRC20 atomic, sat for BTC).
 *     Persisted to `Withdrawal.payout_amount` and sent to the gateway.
 *   - `rateSatPerUnit` — BTC sats per 1 of the target's main unit
 *     at conversion time. Persisted to `Withdrawal.payout_rate` so
 *     a refund or audit can reproduce the math without re-hitting
 *     the oracle.
 *
 * The tuple version was a silent-over/under-payment risk: a caller
 * destructuring `[$rate, $amount] = $oracle->...()` (slots swapped)
 * would persist a rate as the amount and vice versa. PHPStan can
 * verify the tuple shape but cannot verify which slot a caller maps
 * to which column. Named accessors fix that — `$conv->targetAmount`
 * and `$conv->rateSatPerUnit` are unambiguous.
 *
 * Both fields are stringified bcmath decimals (not int) because the
 * target unit can exceed signed-64-bit (ETH wei × multi-BTC balance)
 * and the rate carries up to 18 fractional digits.
 */
final class PayoutConversion
{
    public function __construct(
        public readonly string $targetAmount,
        public readonly string $rateSatPerUnit,
    ) {}
}
