<?php

declare(strict_types=1);

namespace Tests\Unit\Payout\Btc;

use App\Payout\Btc\BtcAddress;
use App\Payout\Btc\BtcTxSigner;
use Elliptic\EC;
use PHPUnit\Framework\TestCase;

/**
 * Pins the BIP143 P2WPKH signer.
 *
 * We DON'T compare against the BIP143 reference vector hex byte-for-
 * byte because that vector mixes legacy + segwit inputs and uses
 * SIGHASH flags we don't support. Instead the strategy is:
 *
 *   1. Determinism — same input twice yields same hex (RFC6979).
 *   2. Round-trip — we recompute the BIP143 sighash for a known input
 *      and verify the produced DER signature against the curve.
 *   3. Shape — signed tx starts with `0200000000010100...` (version
 *      02 LE + segwit marker + flag + input count 01).
 *
 * That covers "the signer produces a valid Bitcoin signature for the
 * tx it claims to be signing" — the actual broadcast acceptance is
 * tested out-of-band against testnet from operations.
 */
class BtcTxSignerTest extends TestCase
{
    /** Test private key — well-known "01" key. NOT funded. */
    private const TEST_PRIV = '0000000000000000000000000000000000000000000000000000000000000001';

    private const RECIPIENT = 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4';

    private function sampleInputs(): array
    {
        return [[
            'txid' => str_repeat('a', 64),
            'vout' => 0,
            'value' => 100_000,
            'privKeyHex' => self::TEST_PRIV,
        ]];
    }

    private function sampleOutputs(): array
    {
        return [[
            'scriptPubKey' => BtcAddress::toScriptPubKey(self::RECIPIENT),
            'value' => 90_000,
        ]];
    }

    public function test_signed_tx_starts_with_segwit_marker(): void
    {
        $signer = new BtcTxSigner;
        $hex = $signer->signSegwit($this->sampleInputs(), $this->sampleOutputs());
        // version=2 LE = 02000000, segwit marker+flag = 0001
        $this->assertStringStartsWith('020000000001', $hex);
    }

    public function test_same_input_yields_same_output_rfc6979(): void
    {
        // Critical: a flaky retry that produces TWO different sigs for
        // the same (key, prevouts) pair would put both in mempool —
        // one would mine, one would silently fail "double-spend".
        $signer = new BtcTxSigner;
        $a = $signer->signSegwit($this->sampleInputs(), $this->sampleOutputs());
        $b = $signer->signSegwit($this->sampleInputs(), $this->sampleOutputs());
        $this->assertSame($a, $b);
    }

    public function test_signature_in_witness_verifies_against_curve(): void
    {
        // Round-trip: re-derive the BIP143 sighash for the input we
        // signed, then verify the DER signature against simplito's
        // ECDSA verifier. If this passes, the signature is on-curve
        // and matches the message hash — exactly what bitcoind checks.
        $signer = new BtcTxSigner;
        $inputs = $this->sampleInputs();
        $outputs = $this->sampleOutputs();
        $hex = $signer->signSegwit($inputs, $outputs);
        $bytes = (string) hex2bin($hex);

        // Signed tx layout (single input, single output):
        // version(4) + 0x00 0x01 + input_count(1) + outpoint(32+4)
        // + scriptSig_len(1=0) + nSequence(4) + output_count(1)
        // + value(8) + scriptPubKey_len(1) + scriptPubKey(22)
        // + witness_stack(1=2) + sig_len + sig + pubkey_len + pubkey
        // + locktime(4)

        // Find the witness section by counting forward from a known
        // anchor: it's right after the outputs and before locktime.
        // Easier: peel from the end — last 4 bytes are locktime,
        // before that is the witness stack.
        $locktimeAt = strlen($bytes) - 4;
        // Walk from the end of outputs (we know layout exactly).
        // Outputs start: 4 (ver) + 2 (marker+flag) + 1 (in_count) + 36 (outpoint)
        //               + 1 (scriptSig_len) + 4 (seq) + 1 (out_count) = 49
        $outsAt = 49;
        $value = unpack('Pvalue', substr($bytes, $outsAt, 8))['value'];
        $scriptLen = ord($bytes[$outsAt + 8]);
        $witnessAt = $outsAt + 8 + 1 + $scriptLen;

        // Witness: stack count varint = 2, then sig_len varint, sig,
        // pubkey_len varint (33), pubkey.
        $stackCount = ord($bytes[$witnessAt]);
        $this->assertSame(2, $stackCount);
        $sigLen = ord($bytes[$witnessAt + 1]);
        $sig = substr($bytes, $witnessAt + 2, $sigLen);
        // Last byte of sig is the SIGHASH_ALL flag (0x01).
        $this->assertSame("\x01", substr($sig, -1));
        $derSig = substr($sig, 0, -1);

        $pubkeyAt = $witnessAt + 2 + $sigLen;
        $pubkeyLen = ord($bytes[$pubkeyAt]);
        $this->assertSame(33, $pubkeyLen); // compressed
        $pubkey = substr($bytes, $pubkeyAt + 1, 33);

        // Re-derive the sighash for input 0 to verify the signature.
        // We can use the signer's own internals via reflection, or
        // re-implement the BIP143 preimage here. Easier: trust the
        // signer + verify the signature is structurally a valid
        // ECDSA DER on the correct curve for the correct pubkey.
        $ec = new EC('secp256k1');
        $key = $ec->keyFromPublic(bin2hex($pubkey), 'hex');

        // We need the same sighash the signer used. Because the signer
        // is private, do the BIP143 preimage assembly here too:
        $sigHash = $this->computeBip143Sighash($inputs, $outputs);

        // simplito's verify() expects HEX strings on both sides.
        $this->assertTrue(
            $key->verify(bin2hex($sigHash), bin2hex($derSig)),
            'witness signature must verify against the derived public key',
        );
        // suppress unused-var warning
        unset($value, $locktimeAt);
    }

    /**
     * @param  array<int, array<string, mixed>>  $inputs
     * @param  array<int, array<string, mixed>>  $outputs
     */
    private function computeBip143Sighash(array $inputs, array $outputs): string
    {
        $in = $inputs[0];
        $ec = new EC('secp256k1');
        $key = $ec->keyFromPrivate($in['privKeyHex'], 'hex');
        $pub = $key->getPublic();
        $x = str_pad($pub->getX()->toString(16), 64, '0', STR_PAD_LEFT);
        $prefix = $pub->getY()->isOdd() ? "\x03" : "\x02";
        $pubkey = $prefix.(string) hex2bin($x);
        $pkh = hash('ripemd160', hash('sha256', $pubkey, true), true);
        $scriptCode = "\x19\x76\xa9\x14".$pkh."\x88\xac";

        $hashPrevouts = hash('sha256', hash('sha256',
            strrev((string) hex2bin($in['txid'])).pack('V', $in['vout']),
            true,
        ), true);
        $hashSequence = hash('sha256', hash('sha256', pack('V', 0xFFFFFFFF), true), true);
        $outsCat = '';
        foreach ($outputs as $o) {
            $outsCat .= pack('P', $o['value']).chr(strlen($o['scriptPubKey'])).$o['scriptPubKey'];
        }
        $hashOutputs = hash('sha256', hash('sha256', $outsCat, true), true);

        $preimage = pack('V', 2)
            .$hashPrevouts.$hashSequence
            .strrev((string) hex2bin($in['txid']))
            .pack('V', $in['vout'])
            .$scriptCode
            .pack('P', $in['value'])
            .pack('V', 0xFFFFFFFF)
            .$hashOutputs
            .pack('V', 0)
            .pack('V', 0x01);

        return hash('sha256', hash('sha256', $preimage, true), true);
    }
}
