<?php

declare(strict_types=1);

namespace App\Payout\Btc;

use Elliptic\EC;
use InvalidArgumentException;

/**
 * BIP143 P2WPKH segwit transaction builder + signer.
 *
 * Limitations of this implementation (intentional, scoped to v1):
 *   - Only P2WPKH inputs (the operator's hot wallet must use a
 *     single bech32 address — `bc1q...`). Mixing legacy P2PKH
 *     inputs is not supported.
 *   - Only P2WPKH outputs (the recipient must also be `bc1q...`).
 *     Legacy P2PKH / P2SH destinations are rejected at controller
 *     validation time.
 *   - SIGHASH_ALL only (the only flag the hot-wallet path uses).
 *
 * The flow per `signSegwit`:
 *   1. For each input, derive scriptCode = 0x1976a914 || pkh || 88ac.
 *   2. Compute hashPrevouts / hashSequence / hashOutputs (3 hashes
 *      shared across all inputs).
 *   3. For each input: assemble BIP143 preimage, sha256² it, sign
 *      the digest with simplito (canonical low-s), DER-encode, append
 *      sighash type byte (0x01).
 *   4. Build the segwit-serialised tx with witness stack
 *      [signature, compressed pubkey] for each input.
 *   5. Hex-encode for `BtcHttpClient::broadcast()`.
 *
 * Test coverage: against BIP143's published P2WPKH test vector +
 * a synthetic round-trip (sign → re-derive sighash → verify with
 * simplito's secp256k1 verifier).
 */
class BtcTxSigner
{
    private readonly EC $ec;

    public function __construct()
    {
        $this->ec = new EC('secp256k1');
    }

    /**
     * Build, sign, and serialise a P2WPKH segwit transaction.
     *
     * `$inputs`: list of `{txid (hex 32B), vout (int), value (sat int),
     *           privKeyHex (64-char hex)}`. The signer derives the
     *           pubkey + scriptCode internally.
     * `$outputs`: list of `{scriptPubKey (binary 22B), value (sat int)}`.
     *           Caller produces scriptPubKey via `BtcAddress::toScriptPubKey`.
     *
     * Returns the segwit-serialised transaction as 0x-less hex ready for
     * mempool.space `POST /tx`.
     *
     * @param  array<int, array{txid: string, vout: int, value: int, privKeyHex: string}>  $inputs
     * @param  array<int, array{scriptPubKey: string, value: int}>  $outputs
     */
    public function signSegwit(array $inputs, array $outputs, int $version = 2, int $locktime = 0): string
    {
        if ($inputs === []) {
            throw new InvalidArgumentException('signSegwit requires at least one input');
        }
        if ($outputs === []) {
            throw new InvalidArgumentException('signSegwit requires at least one output');
        }

        // Pre-compute the three shared hashes (BIP143 §3 "Hash{Prevouts,
        // Sequence, Outputs}"). Each is sha256² of the concatenated
        // serialisation across all inputs / outputs.
        $hashPrevouts = $this->hash256($this->concatPrevouts($inputs));
        $hashSequence = $this->hash256($this->concatSequences($inputs));
        $hashOutputs = $this->hash256($this->concatOutputs($outputs));

        // Compute each input's signature + record the witness data.
        $witnesses = [];
        foreach ($inputs as $i => $in) {
            $sig = $this->signOneInput(
                $in, $hashPrevouts, $hashSequence, $hashOutputs, $version, $locktime,
            );
            // Witness for P2WPKH = [signature || SIGHASH_ALL byte, pubkey].
            $pubkey = $this->derivePubkey($in['privKeyHex']);
            $witnesses[$i] = [$sig."\x01", $pubkey];
        }

        return bin2hex($this->serializeSegwitTx($inputs, $outputs, $witnesses, $version, $locktime));
    }

    /**
     * @param  array{txid: string, vout: int, value: int, privKeyHex: string}  $in
     */
    private function signOneInput(
        array $in,
        string $hashPrevouts,
        string $hashSequence,
        string $hashOutputs,
        int $version,
        int $locktime,
    ): string {
        $pubkey = $this->derivePubkey($in['privKeyHex']);
        $pkh = $this->hash160($pubkey);
        // BIP143 P2WPKH scriptCode is the legacy P2PKH script:
        // OP_DUP OP_HASH160 <20> <pkh> OP_EQUALVERIFY OP_CHECKSIG
        // length-prefixed (= 0x19 = 25 total bytes).
        $scriptCode = "\x19\x76\xa9\x14".$pkh."\x88\xac";

        $preimage = pack('V', $version)
            .$hashPrevouts
            .$hashSequence
            .self::reverseHex($in['txid']) // outpoint txid is BIG-endian on-wire reversal
            .pack('V', $in['vout'])
            .$scriptCode
            .pack('P', $in['value'])  // 64-bit LE for the value (sats)
            .pack('V', 0xFFFFFFFF)    // nSequence — final
            .$hashOutputs
            .pack('V', $locktime)
            .pack('V', 0x01);          // SIGHASH_ALL

        // simplito's sign() expects a hex string (or byte array); the
        // raw binary that hash() emits trips gmp_init when fed straight
        // through. bin2hex it before handing off.
        $digestHex = bin2hex($this->hash256($preimage));

        $key = $this->ec->keyFromPrivate($in['privKeyHex'], 'hex');
        // canonical=true forces low-s — Bitcoin enforces it as a soft
        // fork (BIP66) and rejects high-s signatures since 2015.
        $signature = $key->sign($digestHex, ['canonical' => true]);

        // DER encoding is the canonical Bitcoin signature shape; simplito
        // emits the bytes directly via toDER().
        $der = $signature->toDER();

        return is_array($der) ? self::byteArrayToString($der) : (string) $der;
    }

    /**
     * @param  array<int, array{txid: string, vout: int}>  $inputs
     */
    private function concatPrevouts(array $inputs): string
    {
        $out = '';
        foreach ($inputs as $in) {
            $out .= self::reverseHex($in['txid']).pack('V', $in['vout']);
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $inputs
     */
    private function concatSequences(array $inputs): string
    {
        // All inputs use nSequence = 0xffffffff (final, no RBF).
        return str_repeat(pack('V', 0xFFFFFFFF), count($inputs));
    }

    /**
     * @param  array<int, array{scriptPubKey: string, value: int}>  $outputs
     */
    private function concatOutputs(array $outputs): string
    {
        $out = '';
        foreach ($outputs as $o) {
            $script = $o['scriptPubKey'];
            $out .= pack('P', $o['value']).self::varInt(strlen($script)).$script;
        }

        return $out;
    }

    /**
     * Serialise the final segwit tx (BIP141 wire format).
     *
     * @param  array<int, array{txid: string, vout: int}>  $inputs
     * @param  array<int, array{scriptPubKey: string, value: int}>  $outputs
     * @param  array<int, array<int, string>>  $witnesses
     */
    private function serializeSegwitTx(array $inputs, array $outputs, array $witnesses, int $version, int $locktime): string
    {
        $tx = pack('V', $version);
        $tx .= "\x00\x01"; // SegWit marker + flag
        $tx .= self::varInt(count($inputs));
        foreach ($inputs as $in) {
            $tx .= self::reverseHex($in['txid'])
                .pack('V', $in['vout'])
                .self::varInt(0)        // empty scriptSig for native segwit
                .pack('V', 0xFFFFFFFF); // nSequence
        }
        $tx .= self::varInt(count($outputs));
        foreach ($outputs as $o) {
            $tx .= pack('P', $o['value']).self::varInt(strlen($o['scriptPubKey'])).$o['scriptPubKey'];
        }
        // Witness: stack-count varint + per-item length-prefixed.
        foreach ($witnesses as $stack) {
            $tx .= self::varInt(count($stack));
            foreach ($stack as $item) {
                $tx .= self::varInt(strlen($item)).$item;
            }
        }
        $tx .= pack('V', $locktime);

        return $tx;
    }

    /**
     * Compressed secp256k1 public key (33 bytes: 0x02/0x03 || X).
     */
    private function derivePubkey(string $privKeyHex): string
    {
        $key = $this->ec->keyFromPrivate($privKeyHex, 'hex');
        $pub = $key->getPublic();
        $x = str_pad($pub->getX()->toString(16), 64, '0', STR_PAD_LEFT);
        $y = $pub->getY();
        $prefix = $y->isOdd() ? "\x03" : "\x02";

        return $prefix.(string) hex2bin($x);
    }

    /** sha256(sha256(x)) — Bitcoin's "hash256". */
    private function hash256(string $bytes): string
    {
        return hash('sha256', hash('sha256', $bytes, true), true);
    }

    /** ripemd160(sha256(x)) — Bitcoin's "hash160" (used for pubkey hashes). */
    private function hash160(string $bytes): string
    {
        return hash('ripemd160', hash('sha256', $bytes, true), true);
    }

    /** Decode hex and reverse byte order (txid on-wire is little-endian). */
    private static function reverseHex(string $hex): string
    {
        $bytes = (string) hex2bin($hex);

        return strrev($bytes);
    }

    /** Compact-size varint (BTC's varint, NOT LEB128). */
    private static function varInt(int $n): string
    {
        if ($n < 0xFD) {
            return chr($n);
        }
        if ($n <= 0xFFFF) {
            return "\xfd".pack('v', $n);
        }
        if ($n <= 0xFFFFFFFF) {
            return "\xfe".pack('V', $n);
        }

        return "\xff".pack('P', $n);
    }

    /**
     * @param  array<int, int>  $bytes
     */
    private static function byteArrayToString(array $bytes): string
    {
        $s = '';
        foreach ($bytes as $b) {
            $s .= chr($b);
        }

        return $s;
    }
}
