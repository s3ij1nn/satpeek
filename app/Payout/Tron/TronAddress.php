<?php

declare(strict_types=1);

namespace App\Payout\Tron;

/**
 * Base58Check validator for Tron addresses (mainnet T-prefix).
 *
 * The address format is identical to Bitcoin's P2PKH but with a different
 * version byte (0x41 for Tron mainnet vs 0x00 for BTC). 25 bytes total:
 *   - 1 byte version (0x41)
 *   - 20 bytes hash (Keccak256(public_key)[12..32], Ethereum-style)
 *   - 4 bytes checksum (first 4 bytes of double-SHA256 of the first 21)
 *
 * Encoded as Base58 → 34 chars starting with 'T'. We accept ONLY mainnet
 * here; TronGrid Shasta testnet uses the same prefix so the same check
 * applies — the network distinction is in the RPC URL, not the address.
 *
 * Pure-PHP implementation: ext-gmp / simplito-elliptic-php / etc are only
 * needed for SIGNING (Phase 2b). Validation just walks Base58 alphabet +
 * runs SHA256 twice — both available natively in PHP via `hash()`.
 *
 * Threat model: address validation is the first line of defence against
 * a typo'd or malicious destination on the withdrawal form. A wrong
 * address means the broadcast might still succeed (Tron doesn't require
 * receivable address validation by the sender) and funds become
 * irrecoverable. We refuse to even queue a withdrawal whose destination
 * fails the checksum.
 */
final class TronAddress
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    private const ADDRESS_BYTES = 25;

    /**
     * Convert a valid Base58Check Tron address into its 20-byte hex form
     * (no `0x41` prefix). Used by TRC20 ABI parameter encoding —
     * ERC20-style `transfer(address,uint256)` expects the 20-byte
     * EVM address shape, NOT the 21-byte Tron form. Throws if the
     * input fails Base58Check validation.
     */
    public static function toHash20(string $address): string
    {
        if (! self::isValid($address)) {
            throw new \InvalidArgumentException("invalid tron address: {$address}");
        }
        $decoded = self::base58Decode($address);

        // 25 bytes = 1 version + 20 hash + 4 checksum. Return the
        // middle 20 as hex — this is what ABI encoding expects.
        return bin2hex(substr((string) $decoded, 1, 20));
    }

    public static function isValid(string $address): bool
    {
        // Cheap structural rejects first — Base58Check decoding is more
        // expensive than checking the obvious shape.
        if (strlen($address) !== 34) {
            return false;
        }
        if ($address[0] !== 'T') {
            return false;
        }

        $decoded = self::base58Decode($address);
        if ($decoded === null || strlen($decoded) !== self::ADDRESS_BYTES) {
            return false;
        }

        // Mainnet version byte. (Shasta uses the same prefix because the
        // address format is network-agnostic — only the RPC URL changes.)
        if (ord($decoded[0]) !== 0x41) {
            return false;
        }

        // Last 4 bytes are checksum = first 4 bytes of double-SHA256 of
        // the leading 21 bytes (version + hash160-style payload). Use
        // hash_equals to defeat timing-leak shenanigans even though
        // an address validator isn't a typical leak surface.
        $payload = substr($decoded, 0, 21);
        $checksum = substr($decoded, 21, 4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        return hash_equals($expected, $checksum);
    }

    private static function base58Decode(string $input): ?string
    {
        $alphabet = self::ALPHABET;
        $base = strlen($alphabet);

        // Count leading '1's — they map to leading null bytes in the
        // decoded form. Tron addresses never have leading '1' (they
        // start with 'T') but we keep the canonical Base58 logic so a
        // future caller passing a non-Tron address gets the right
        // behaviour rather than a silent wrong answer.
        $leadingOnes = 0;
        for ($i = 0; $i < strlen($input); $i++) {
            if ($input[$i] === '1') {
                $leadingOnes++;
            } else {
                break;
            }
        }

        // Big-integer accumulator without ext-gmp: process the input
        // as a base-58 number, output as base-256 bytes. We use raw
        // string arithmetic — slower than gmp but the values are
        // bounded (max ~25 bytes) so the cost is negligible.
        $bytes = [0];
        for ($i = 0; $i < strlen($input); $i++) {
            $charPos = strpos($alphabet, $input[$i]);
            if ($charPos === false) {
                return null;
            }
            $carry = $charPos;
            foreach ($bytes as $j => $byte) {
                $carry += $byte * $base;
                $bytes[$j] = $carry & 0xFF;
                $carry >>= 8;
            }
            while ($carry > 0) {
                $bytes[] = $carry & 0xFF;
                $carry >>= 8;
            }
        }

        // Bytes were accumulated little-endian; reverse to big-endian.
        $bytes = array_reverse($bytes);
        // Strip the leading 0 carry byte that the seed-bytes-[0]=0 line
        // introduced when the input had no leading-'1' padding.
        while (count($bytes) > 0 && $bytes[0] === 0 && $leadingOnes > 0) {
            $leadingOnes--;
            array_shift($bytes);
        }
        // Re-add explicit leading zeros for any unmatched leading '1's.
        for ($i = 0; $i < $leadingOnes; $i++) {
            array_unshift($bytes, 0);
        }

        return implode('', array_map('chr', $bytes));
    }
}
