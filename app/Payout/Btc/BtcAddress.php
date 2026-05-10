<?php

declare(strict_types=1);

namespace App\Payout\Btc;

use BitWasp\Bech32\Exception\Bech32Exception;
use InvalidArgumentException;

use function BitWasp\Bech32\decode;

/**
 * Validator + utility for Bitcoin addresses.
 *
 * Phase 3b ships P2WPKH (bech32 `bc1q...` mainnet, `tb1q...` testnet)
 * as the only supported destination type. Modern wallets emit P2WPKH
 * by default; legacy P2PKH (`1...`) and P2SH (`3...`) come back later
 * if there's demand. SegWit txs cost ~30% less in fees and decode
 * more efficiently.
 *
 * Bech32 structure:
 *   - HRP (`bc` or `tb`) + separator (`1`) + data part (32-char witness
 *     program + 6-char checksum)
 *   - First word is the witness version (0 for v0 segwit / P2WPKH-or-WSH)
 *   - Remaining words are the witness program (20 bytes for P2WPKH,
 *     32 bytes for P2WSH)
 *
 * Threat model: same as TronAddress / EthAddress — first line of
 * defence against typo'd or malicious destinations. BTC sends are
 * irreversible by protocol; we refuse to even queue a withdrawal
 * whose destination fails the bech32 checksum or has the wrong HRP /
 * witness version.
 */
final class BtcAddress
{
    /** Mainnet HRP. */
    public const HRP_MAINNET = 'bc';

    /** Testnet (signet/testnet) HRP. */
    public const HRP_TESTNET = 'tb';

    public static function isValid(string $address, string $expectedHrp = self::HRP_MAINNET): bool
    {
        // Cheap structural rejects first — bech32 is 14-74 chars, lowercase
        // (mixed-case is invalid per spec).
        $len = strlen($address);
        if ($len < 14 || $len > 74) {
            return false;
        }
        if (strtolower($address) !== $address && strtoupper($address) !== $address) {
            return false;
        }
        // Normalise to lowercase for the decoder (spec allows uppercase
        // input but the canonical form is lowercase).
        $normalised = strtolower($address);

        try {
            [$hrp, $words] = decode($normalised);
        } catch (Bech32Exception) {
            return false;
        }
        if ($hrp !== $expectedHrp) {
            return false;
        }
        // For P2WPKH the first word is the witness version (must be 0)
        // followed by the 5-bit-encoded 20-byte program (= 32 words).
        if (count($words) !== 33) {
            return false;
        }
        if ($words[0] !== 0) {
            return false;
        }

        // Decode to verify the program is exactly 20 bytes.
        $program = self::wordsToBytes(array_slice($words, 1));

        return $program !== null && strlen($program) === 20;
    }

    /**
     * Return the 20-byte witness program (the pubkey hash) for a valid
     * P2WPKH address. Used by the BIP143 sighash + UTXO scriptCode.
     * Throws on a malformed address — caller MUST gate on isValid first.
     */
    public static function toPubkeyHash(string $address, string $expectedHrp = self::HRP_MAINNET): string
    {
        if (! self::isValid($address, $expectedHrp)) {
            throw new InvalidArgumentException("invalid bitcoin p2wpkh address: {$address}");
        }
        [$hrp, $words] = decode(strtolower($address));
        $program = self::wordsToBytes(array_slice($words, 1));
        if ($program === null || strlen($program) !== 20) {
            throw new InvalidArgumentException("address decoded but program length wrong: {$address}");
        }

        return $program;
    }

    /**
     * Return the segwit v0 P2WPKH scriptPubKey for an address.
     * Format: `0x00 || 0x14 || <20-byte pubkey hash>` (22 bytes total).
     */
    public static function toScriptPubKey(string $address, string $expectedHrp = self::HRP_MAINNET): string
    {
        return "\x00\x14".self::toPubkeyHash($address, $expectedHrp);
    }

    /**
     * Convert bech32 5-bit words to 8-bit bytes (the witness program).
     * Returns null on invalid padding / range.
     *
     * @param  array<int, int>  $words
     */
    private static function wordsToBytes(array $words): ?string
    {
        $acc = 0;
        $bits = 0;
        $out = '';
        foreach ($words as $w) {
            if ($w < 0 || $w > 31) {
                return null;
            }
            $acc = ($acc << 5) | $w;
            $bits += 5;
            while ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($acc >> $bits) & 0xFF);
            }
        }
        // Strict mode: leftover bits must all be zero (bech32 padding rule).
        if ($bits >= 5 || ($acc & ((1 << $bits) - 1)) !== 0) {
            return null;
        }

        return $out;
    }
}
