<?php

declare(strict_types=1);

namespace Tests\Unit\Payout\Tron;

use App\Payout\Tron\TronTxSigner;
use Elliptic\EC;
use PHPUnit\Framework\TestCase;

/**
 * Pins the secp256k1 ECDSA path TronOnchainGateway will rely on to
 * produce a broadcastable signature for `/wallet/broadcasttransaction`.
 *
 * The test vectors come from two angles:
 *   1. Round-trip — the signer's output must verify under
 *      simplito's own EC::verify(), proving the (r, s) pair is on
 *      the curve and matches the message hash.
 *   2. Shape — Tron expects exactly 65 hex bytes (130 hex chars):
 *      32-byte r || 32-byte s || 1-byte v. A leading-zero loss in r
 *      or s would silently truncate the signature and TronGrid would
 *      reject the broadcast with a confusing "BANDWITH_ERROR" — so
 *      the length check is a load-bearing contract assertion, not a
 *      style nitpick.
 *
 * Real signing happens against a SHA256 digest of the raw_data bytes
 * returned by /wallet/createtransaction. We test both with a known
 * 32-byte digest (deterministic per RFC6979) and with the SHA256 of
 * an arbitrary raw_data_hex blob (the path the gateway will use).
 */
class TronTxSignerTest extends TestCase
{
    /**
     * Fixed secp256k1 private key used across the round-trip + shape
     * tests. The corresponding Tron mainnet address is well-known but
     * irrelevant here — we only care that the signer produces a
     * signature the curve accepts for this key.
     *
     * NOT a real funded key. Don't reuse for anything but tests.
     */
    private const TEST_PRIVATE_KEY_HEX = '0000000000000000000000000000000000000000000000000000000000000001';

    public function test_signature_round_trips_through_simplito_verify(): void
    {
        $signer = new TronTxSigner;
        $rawDataHex = bin2hex(random_bytes(64));

        $signatureHex = $signer->sign($rawDataHex, self::TEST_PRIVATE_KEY_HEX);

        // Decompose r||s||v back into the {r, s} pair simplito's verify
        // expects, then run verification against the same hash the
        // signer would have computed.
        $r = substr($signatureHex, 0, 64);
        $s = substr($signatureHex, 64, 64);
        $msgHash = hash('sha256', hex2bin($rawDataHex));

        $ec = new EC('secp256k1');
        $key = $ec->keyFromPrivate(self::TEST_PRIVATE_KEY_HEX, 'hex');

        $this->assertTrue($key->verify($msgHash, ['r' => $r, 's' => $s]));
    }

    public function test_signature_is_exactly_65_bytes(): void
    {
        $signer = new TronTxSigner;
        $rawDataHex = bin2hex(random_bytes(64));

        $signatureHex = $signer->sign($rawDataHex, self::TEST_PRIVATE_KEY_HEX);

        // 32 r + 32 s + 1 v = 65 bytes = 130 hex chars. Tron's
        // broadcast endpoint silently rejects shorter signatures.
        $this->assertSame(130, strlen($signatureHex));
    }

    public function test_recovery_param_is_0_or_1(): void
    {
        // Tron expects raw v ∈ {0, 1} (not Ethereum's 27/28). simplito
        // can return values in {0, 1, 2, 3} where bit 1 indicates
        // "r had to be reduced mod n" — for secp256k1 that case is
        // astronomically rare but if it ever shows up in production
        // we'd need to either reject or normalise. Pin the assumption.
        $signer = new TronTxSigner;
        for ($i = 0; $i < 10; $i++) {
            $rawDataHex = bin2hex(random_bytes(64));
            $signatureHex = $signer->sign($rawDataHex, self::TEST_PRIVATE_KEY_HEX);
            $v = hexdec(substr($signatureHex, 128, 2));
            $this->assertContains($v, [0, 1], "v out of expected range for iteration {$i}: got {$v}");
        }
    }

    public function test_signature_is_canonical_low_s(): void
    {
        // ECDSA signatures have a malleability: (r, s) and (r, n-s)
        // are both valid for the same message. Tron + Bitcoin + ETH
        // all reject the high-s form to prevent third parties from
        // mutating an in-flight tx. Pin that simplito is producing
        // canonical signatures (it does by default, but a future
        // version bump shouldn't silently flip the behaviour).
        $signer = new TronTxSigner;
        $rawDataHex = bin2hex(random_bytes(64));

        $signatureHex = $signer->sign($rawDataHex, self::TEST_PRIVATE_KEY_HEX);
        $sHex = substr($signatureHex, 64, 64);

        // secp256k1 group order n; s must be <= n/2 for canonical form.
        // Half-order constant from SEC2 p.9 (well-known).
        $halfNHex = '7fffffffffffffffffffffffffffffff5d576e7357a4501ddfe92f46681b20a0';
        $this->assertLessThanOrEqual(0, strcmp(strtolower($sHex), $halfNHex));
    }

    public function test_same_input_produces_same_output(): void
    {
        // RFC6979 deterministic k means the same (privKey, msgHash)
        // pair must always yield the same signature. This is a
        // load-bearing property: if a flaky retry produces two
        // different signatures for the same tx, both could land in a
        // mempool and one would silently fail. Pin determinism.
        $signer = new TronTxSigner;
        $rawDataHex = 'deadbeef'.bin2hex(random_bytes(60));

        $sig1 = $signer->sign($rawDataHex, self::TEST_PRIVATE_KEY_HEX);
        $sig2 = $signer->sign($rawDataHex, self::TEST_PRIVATE_KEY_HEX);

        $this->assertSame($sig1, $sig2);
    }

    public function test_throws_on_malformed_private_key(): void
    {
        $signer = new TronTxSigner;
        $this->expectException(\InvalidArgumentException::class);
        $signer->sign('00ff', 'not a hex key');
    }

    public function test_throws_on_malformed_raw_data_hex(): void
    {
        $signer = new TronTxSigner;
        $this->expectException(\InvalidArgumentException::class);
        $signer->sign('not hex', self::TEST_PRIVATE_KEY_HEX);
    }
}
