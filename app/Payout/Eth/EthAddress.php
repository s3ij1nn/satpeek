<?php

declare(strict_types=1);

namespace App\Payout\Eth;

use kornrunner\Keccak;

/**
 * Validator + utility for Ethereum addresses.
 *
 * Two valid forms:
 *   - all-lowercase 0x-prefixed 40 hex chars (no checksum guarantee)
 *   - EIP-55 mixed-case 0x-prefixed 40 hex chars (checksum-protected)
 *
 * EIP-55 derives the case of each hex char from the keccak256 hash
 * of the lowercase address: char[i] is uppercase iff hash[i] >= 8.
 * Mixed-case addresses that don't match this pattern are rejected
 * — wallets standardised on EIP-55 in 2017 because hand-typed
 * addresses with one transposed character would otherwise pass
 * the format check and lose user funds.
 *
 * Threat model: same as TronAddress — address validation is the
 * first line of defence against typo'd or malicious destinations
 * on the withdrawal form. ETH transfers are irreversible by
 * protocol; we refuse to even queue a withdrawal whose destination
 * fails this check.
 */
final class EthAddress
{
    public static function isValid(string $address): bool
    {
        // Cheap structural rejects first. Accept either `0x` or `0X`
        // prefix — some early tooling emits the uppercase form.
        if (! preg_match('/^0[xX][0-9a-fA-F]{40}$/', $address)) {
            return false;
        }
        $hex = substr($address, 2); // strip 0x / 0X
        // All-lowercase or all-uppercase: skip checksum check (the
        // sender opted out of EIP-55 protection — we still accept
        // because pre-2017 wallets and tooling produced these).
        if ($hex === strtolower($hex) || $hex === strtoupper($hex)) {
            return true;
        }

        // EIP-55: for each LETTER (digits are case-neutral), the
        // letter is uppercase iff the corresponding nibble of
        // keccak256(lowercase-hex-ascii) is >= 8.
        $lower = strtolower($hex);
        $hash = Keccak::hash($lower, 256);
        for ($i = 0; $i < 40; $i++) {
            // Digits 0-9 are not part of the case-checksum — skip.
            if (ctype_digit($hex[$i])) {
                continue;
            }
            $hashNibble = hexdec($hash[$i]);
            $isUpper = ctype_upper($hex[$i]);
            if ($hashNibble >= 8 && ! $isUpper) {
                return false;
            }
            if ($hashNibble < 8 && $isUpper) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return the 20-byte BINARY representation of the address (no
     * 0x prefix, no hex). Used in RLP encoding for the `to` field
     * of an EIP-1559 transaction. Throws on a malformed address —
     * caller MUST gate on isValid() first.
     */
    public static function toBytes(string $address): string
    {
        if (! self::isValid($address)) {
            throw new \InvalidArgumentException("invalid eth address: {$address}");
        }

        return (string) hex2bin(substr(strtolower($address), 2));
    }
}
