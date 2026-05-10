<?php

declare(strict_types=1);

namespace App\Payout\Eth;

use InvalidArgumentException;

/**
 * Recursive Length Prefix encoder — Ethereum's canonical
 * serialization for transactions, blocks, accounts, and any other
 * structured data that needs a deterministic byte representation
 * for hashing.
 *
 * Encoding rules (Ethereum Yellow Paper § Appendix B):
 *   - byte 0x00–0x7f          → encoded as itself (1 byte)
 *   - string len 0–55         → 0x80+len, then string
 *   - string len > 55         → 0xb7+len-of-len, len, then string
 *   - list payload 0–55       → 0xc0+len, then payload
 *   - list payload > 55       → 0xf7+len-of-len, len, then payload
 *
 * Integers are encoded as the BIG-ENDIAN byte representation with
 * NO leading zeros (and zero itself is the empty string). A bug in
 * leading-zero stripping silently produces a different signing
 * hash and node rejection — pinned by `EthRlpTest` against the
 * Ethereum test vectors.
 *
 * Pure PHP + ext-gmp (already required by `simplito/elliptic-php`)
 * for arbitrary-precision arithmetic. ETH wei × multi-ETH transfer
 * amounts overflow int64; we never coerce to int internally.
 */
final class EthRlp
{
    /**
     * Encode a non-negative integer expressed as a decimal string.
     * Returns the RLP byte string. Pass '0' for the empty-byte
     * convention (NOT '\x00' — that's a string, encoded differently).
     */
    public static function encodeUint(string $decimal): string
    {
        if (! ctype_digit($decimal)) {
            throw new InvalidArgumentException("decimal must be non-negative digits, got: {$decimal}");
        }
        $g = gmp_init($decimal, 10);
        if (gmp_cmp($g, 0) === 0) {
            return self::encodeBytes('');
        }
        $hex = gmp_strval($g, 16);
        if (strlen($hex) % 2 !== 0) {
            $hex = '0'.$hex;
        }

        return self::encodeBytes((string) hex2bin($hex));
    }

    /** Encode a raw byte string (e.g. the recipient's 20-byte address). */
    public static function encodeBytes(string $bytes): string
    {
        $len = strlen($bytes);
        // Single byte < 0x80 is encoded as itself — saves the 1-byte
        // length prefix in the very common short-string case.
        if ($len === 1 && ord($bytes) < 0x80) {
            return $bytes;
        }
        if ($len <= 55) {
            return chr(0x80 + $len).$bytes;
        }
        $lenBytes = self::intToBytes($len);

        return chr(0xB7 + strlen($lenBytes)).$lenBytes.$bytes;
    }

    /**
     * Encode a list of ALREADY-RLP-ENCODED items (each item must be
     * the output of one of the encode* methods above).
     *
     * @param  array<int, string>  $rlpEncodedItems
     */
    public static function encodeList(array $rlpEncodedItems): string
    {
        $payload = implode('', $rlpEncodedItems);
        $len = strlen($payload);
        if ($len <= 55) {
            return chr(0xC0 + $len).$payload;
        }
        $lenBytes = self::intToBytes($len);

        return chr(0xF7 + strlen($lenBytes)).$lenBytes.$payload;
    }

    /** Big-endian no-leading-zero byte representation of a positive int. */
    private static function intToBytes(int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        $bytes = '';
        while ($n > 0) {
            $bytes = chr($n & 0xFF).$bytes;
            $n >>= 8;
        }

        return $bytes;
    }
}
