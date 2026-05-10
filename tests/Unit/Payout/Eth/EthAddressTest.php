<?php

declare(strict_types=1);

namespace Tests\Unit\Payout\Eth;

use App\Payout\Eth\EthAddress;
use PHPUnit\Framework\TestCase;

/**
 * Pins the EIP-55 checksum validator. Test addresses come from the
 * EIP-55 reference (https://eips.ethereum.org/EIPS/eip-55) — these
 * are the canonical examples used by every wallet implementation.
 */
class EthAddressTest extends TestCase
{
    public function test_accepts_eip55_checksum_addresses(): void
    {
        // From EIP-55 spec — known valid mixed-case addresses.
        $valid = [
            '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed',
            '0xfB6916095ca1df60bB79Ce92cE3Ea74c37c5d359',
            '0xdbF03B407c01E7cD3CBea99509d93f8DDDC8C6FB',
            '0xD1220A0cf47c7B9Be7A2E6BA89F429762e7b9aDb',
        ];
        foreach ($valid as $addr) {
            $this->assertTrue(EthAddress::isValid($addr), "expected valid: {$addr}");
        }
    }

    public function test_accepts_all_lowercase_address(): void
    {
        // Pre-EIP-55 wallets emit all-lowercase. Still accepted (no
        // checksum guarantee but format is valid).
        $this->assertTrue(EthAddress::isValid('0x5aaeb6053f3e94c9b9a09f33669435e7ef1beaed'));
    }

    public function test_accepts_all_uppercase_address(): void
    {
        // Some early tools emit all-uppercase — also no checksum,
        // but the format is well-defined.
        $this->assertTrue(EthAddress::isValid('0X5AAEB6053F3E94C9B9A09F33669435E7EF1BEAED'));
    }

    public function test_rejects_mixed_case_with_wrong_checksum(): void
    {
        // Single bit flipped from the EIP-55 valid form above —
        // last 'd' uppercased to 'D'. Must reject.
        $this->assertFalse(EthAddress::isValid('0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAeD'));
    }

    public function test_rejects_wrong_length(): void
    {
        $this->assertFalse(EthAddress::isValid('0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAe'));   // 39 hex
        $this->assertFalse(EthAddress::isValid('0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed00')); // 42 hex
    }

    public function test_rejects_no_0x_prefix(): void
    {
        $this->assertFalse(EthAddress::isValid('5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed'));
    }

    public function test_rejects_non_hex(): void
    {
        $this->assertFalse(EthAddress::isValid('0xZZAeb6053F3E94C9b9A09f33669435E7Ef1BeAed'));
    }

    public function test_to_bytes_returns_20_byte_binary(): void
    {
        $bytes = EthAddress::toBytes('0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed');
        $this->assertSame(20, strlen($bytes));
        $this->assertSame('5aaeb6053f3e94c9b9a09f33669435e7ef1beaed', bin2hex($bytes));
    }

    public function test_to_bytes_throws_on_invalid_address(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EthAddress::toBytes('not-an-address');
    }
}
