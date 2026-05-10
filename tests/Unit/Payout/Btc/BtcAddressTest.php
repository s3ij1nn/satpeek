<?php

declare(strict_types=1);

namespace Tests\Unit\Payout\Btc;

use App\Payout\Btc\BtcAddress;
use PHPUnit\Framework\TestCase;

/**
 * Pins the bech32 P2WPKH validator + 20-byte pubkey-hash extraction.
 * Test addresses come from BIP173's reference vectors plus a few
 * well-known mainnet wallets.
 */
class BtcAddressTest extends TestCase
{
    /** BIP173 reference example — pubkey hash = 751e76e8199196d454941c45d1b3a323f1433bd6. */
    private const BIP173_REF = 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4';

    public function test_accepts_canonical_bip173_reference(): void
    {
        $this->assertTrue(BtcAddress::isValid(self::BIP173_REF));
    }

    public function test_extracts_correct_20_byte_pubkey_hash(): void
    {
        // Hash from the BIP173 reference itself.
        $this->assertSame(
            '751e76e8199196d454941c45d1b3a323f1433bd6',
            bin2hex(BtcAddress::toPubkeyHash(self::BIP173_REF)),
        );
    }

    public function test_to_script_pubkey_returns_v0_witness_program(): void
    {
        // P2WPKH scriptPubKey = OP_0 (0x00) || PUSH_20 (0x14) || 20-byte pkh
        $script = BtcAddress::toScriptPubKey(self::BIP173_REF);
        $this->assertSame(22, strlen($script));
        $this->assertSame("\x00\x14", substr($script, 0, 2));
        $this->assertSame('751e76e8199196d454941c45d1b3a323f1433bd6', bin2hex(substr($script, 2)));
    }

    public function test_rejects_testnet_address_with_mainnet_hrp_default(): void
    {
        // Testnet bech32 uses HRP 'tb' — must reject under default mainnet HRP.
        $this->assertFalse(BtcAddress::isValid('tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx'));
    }

    public function test_accepts_testnet_address_when_hrp_specified(): void
    {
        $this->assertTrue(BtcAddress::isValid(
            'tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx',
            BtcAddress::HRP_TESTNET,
        ));
    }

    public function test_rejects_mixed_case(): void
    {
        // Bech32 spec disallows mixed case to defeat homoglyph attacks.
        $this->assertFalse(BtcAddress::isValid('Bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4'));
    }

    public function test_rejects_corrupted_checksum(): void
    {
        // Single-char swap from the BIP173 reference — checksum should fail.
        $bad = substr_replace(self::BIP173_REF, 'p', -1, 1);
        $this->assertFalse(BtcAddress::isValid($bad));
    }

    public function test_rejects_p2wsh_program_length(): void
    {
        // P2WSH bech32 (32-byte program). Our validator is P2WPKH-only —
        // must reject.
        $p2wsh = 'bc1qrp33g0q5c5txsp9arysrx4k6zdkfs4nce4xj0gdcccefvpysxf3qccfmv3';
        $this->assertFalse(BtcAddress::isValid($p2wsh));
    }
}
