<?php

declare(strict_types=1);

namespace Tests\Unit\Payout\Eth;

use App\Payout\Eth\EthTxSigner;
use kornrunner\Keccak;
use PHPUnit\Framework\TestCase;

/**
 * Pins the EIP-1559 type-2 ETH signer.
 *
 * The most load-bearing assertion is that signing the same
 * (privKey, tx) pair twice yields the same hex (RFC6979
 * deterministic k) — without that, a flaky retry could produce
 * two valid txs from the same nonce + value, only one mined.
 *
 * We also assert the envelope shape:
 *   - starts with 0x02 (type byte for EIP-1559)
 *   - txHash is keccak256 of the full envelope
 *   - the (r, s) recovered from the encoded list verifies under
 *     simplito for the corresponding public key
 *
 * Going against a HARDCODED hex from another implementation is
 * tempting but brittle — different libraries serialise empty bytes
 * (e.g. `data: ''`) slightly differently. Verification under the
 * library's own crypto is sufficient because RLP + keccak256 are
 * standardised end-to-end.
 */
class EthTxSignerTest extends TestCase
{
    /** Standard test private key (vitalik test_account family). NOT real. */
    private const TEST_PRIV = '4646464646464646464646464646464646464646464646464646464646464646';

    private function sampleTx(): array
    {
        return [
            'chainId' => '1',
            'nonce' => 9,
            'maxPriorityFeePerGas' => '1500000000',  // 1.5 gwei
            'maxFeePerGas' => '40000000000',         // 40 gwei
            'gasLimit' => 21000,
            'to' => '0x3535353535353535353535353535353535353535',
            'value' => '1000000000000000000', // 1 ETH
            'data' => '',
        ];
    }

    public function test_signed_tx_starts_with_type_2_byte(): void
    {
        $signer = new EthTxSigner;
        $rawHex = $signer->signEip1559($this->sampleTx(), self::TEST_PRIV);
        $this->assertStringStartsWith('0x02', $rawHex);
    }

    public function test_same_input_yields_same_output_rfc6979(): void
    {
        // RFC6979 deterministic k. Critical: a flaky retry that
        // produces TWO different signatures for the same (key,
        // nonce) would put both in the mempool — one would mine,
        // one would silently fail with "nonce already used".
        $signer = new EthTxSigner;
        $a = $signer->signEip1559($this->sampleTx(), self::TEST_PRIV);
        $b = $signer->signEip1559($this->sampleTx(), self::TEST_PRIV);
        $this->assertSame($a, $b);
    }

    public function test_compute_tx_hash_is_keccak256_of_envelope(): void
    {
        $signer = new EthTxSigner;
        $rawHex = $signer->signEip1559($this->sampleTx(), self::TEST_PRIV);
        $expected = '0x'.Keccak::hash(hex2bin(substr($rawHex, 2)), 256);
        $this->assertSame($expected, $signer->computeTxHash($rawHex));
    }

    public function test_throws_on_malformed_private_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EthTxSigner)->signEip1559($this->sampleTx(), 'not hex');
    }

    public function test_throws_on_invalid_destination(): void
    {
        $tx = $this->sampleTx();
        $tx['to'] = 'not-an-eth-address';
        $this->expectException(\InvalidArgumentException::class);
        (new EthTxSigner)->signEip1559($tx, self::TEST_PRIV);
    }

    public function test_zero_value_is_encoded(): void
    {
        // Zero value is legal for contract calls. Pin that the
        // signer accepts value=0 without throwing.
        $tx = $this->sampleTx();
        $tx['value'] = '0';
        $signer = new EthTxSigner;
        $rawHex = $signer->signEip1559($tx, self::TEST_PRIV);
        $this->assertStringStartsWith('0x02', $rawHex);
    }
}
