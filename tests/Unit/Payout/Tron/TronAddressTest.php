<?php

declare(strict_types=1);

namespace Tests\Unit\Payout\Tron;

use App\Payout\Tron\TronAddress;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Tron address validation contract.
 *
 * Test fixtures use real Base58Check-valid mainnet addresses lifted from
 * the public tronscan explorer (USDT-TRC20 contract + a few well-known
 * exchange wallets) so the checksum math is verified against ground-truth
 * — not addresses we encoded ourselves (which could share a bug with the
 * decoder under test).
 */
class TronAddressTest extends TestCase
{
    public function test_well_known_addresses_validate(): void
    {
        // USDT-TRC20 contract (the canonical "address that must validate").
        $this->assertTrue(TronAddress::isValid('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'));
        // Binance hot wallet — high-volume real address.
        $this->assertTrue(TronAddress::isValid('TNaRAoLUyYEV2uF7GUrzSjRQTU8v5ZJ5VR'));
    }

    public function test_typo_in_known_address_fails_checksum(): void
    {
        // USDT contract with last char flipped — Base58 still well-formed,
        // but the 4-byte checksum mismatches.
        $this->assertFalse(TronAddress::isValid('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6T'));
    }

    public function test_wrong_length_rejected(): void
    {
        $this->assertFalse(TronAddress::isValid('T'));
        $this->assertFalse(TronAddress::isValid('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6')); // 33 chars
        $this->assertFalse(TronAddress::isValid('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6tx')); // 35 chars
    }

    public function test_wrong_prefix_rejected(): void
    {
        // Same length, valid Base58 alphabet, but version byte != 0x41.
        // BTC P2PKH (1...) and ETH-hex shapes both fail.
        $this->assertFalse(TronAddress::isValid('1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa'));
    }

    public function test_garbage_input_rejected_safely(): void
    {
        // Defence-in-depth: a malformed input must NEVER throw — the
        // form validator catches `false` and surfaces a clean error;
        // an exception would 500 the request.
        $this->assertFalse(TronAddress::isValid(''));
        $this->assertFalse(TronAddress::isValid('not-an-address'));
        $this->assertFalse(TronAddress::isValid("T\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f"));
        // Non-Base58 character (0, O, I, l).
        $this->assertFalse(TronAddress::isValid('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj60'));
    }
}
