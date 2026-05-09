<?php

declare(strict_types=1);

namespace App\Payout\Gateway;

/**
 * Outcome of a {@see PayoutGateway::send()} call. Single shape across
 * FaucetPay + every onchain transport so `ProcessWithdrawalJob` has
 * one code path.
 *
 * `externalId` is the gateway-specific reference: FaucetPay's
 * `payout_id`, BTC's tx hash, ETH's tx hash, Tron's txID — whatever
 * the support team pastes into a tracker when reconciling. Stored on
 * `Withdrawal.faucetpay_payout_id` for the FP route and
 * `Withdrawal.onchain_tx_hash` for everything else.
 *
 * `raw` is the full response payload (parsed JSON, decoded RPC
 * envelope, etc.) — preserved on `Withdrawal.meta` for forensics
 * without forcing every gateway to invent its own debug shape.
 */
final class PayoutResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $externalId,
        public readonly string $message,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function sent(?string $externalId, string $message, array $raw = []): self
    {
        return new self(true, $externalId, $message, $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function failed(string $message, array $raw = []): self
    {
        return new self(false, null, $message, $raw);
    }
}
