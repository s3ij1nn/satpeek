<?php

declare(strict_types=1);

namespace App\Payout\Tron;

/**
 * ABI parameter encoder for Tron smart-contract calls.
 *
 * TRC20 (and every TRC-N* standard) uses Ethereum's Solidity ABI
 * encoding for function parameters. For `transfer(address,uint256)`
 * — the only call SatPeek needs in Phase 2c — that's two 32-byte
 * left-padded values:
 *
 *   - address (20 bytes) → 12 zero bytes || 20-byte hash
 *   - uint256 (≤ 32 bytes) → (32 - n) zero bytes || amount big-endian
 *
 * Tron's `function_selector` field carries the human-readable
 * signature ("transfer(address,uint256)"); the node hashes it server-
 * side to derive the 4-byte selector. So unlike Ethereum, we do NOT
 * prepend the selector hash to the parameter blob — only the encoded
 * arguments.
 *
 * Pure-PHP, no ext-gmp dependency for the encoding itself (gmp is
 * only used by the signer). USDT-TRC20 amounts fit comfortably in
 * int64 (max 18.4e18 base units = 18.4e12 USDT — well above any
 * conceivable per-tx amount), so dechex is safe here.
 */
final class TronAbi
{
    /**
     * ABI-encode the parameter blob for `transfer(address,uint256)`.
     *
     * @param  string  $recipientBase58  T-prefix Base58Check address
     * @param  int  $amount  amount in the token's smallest unit
     */
    public static function encodeTransfer(string $recipientBase58, int $amount): string
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('amount must be non-negative');
        }

        // Solidity ABI: address arg = 20-byte hash left-padded to 32.
        $addressHex = TronAddress::toHash20($recipientBase58);
        $paddedAddress = str_pad($addressHex, 64, '0', STR_PAD_LEFT);

        // Solidity ABI: uint256 arg = big-endian, left-padded to 32.
        $amountHex = dechex($amount);
        $paddedAmount = str_pad($amountHex, 64, '0', STR_PAD_LEFT);

        return $paddedAddress.$paddedAmount;
    }
}
