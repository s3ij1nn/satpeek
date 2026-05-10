<?php

declare(strict_types=1);

namespace Tests\Unit\Payout\Tron;

use App\Payout\Tron\TronAbi;
use App\Payout\Tron\TronAddress;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Solidity ABI encoding for TRC20 `transfer(address,uint256)`.
 *
 * The encoded parameter string is what TronGrid hashes server-side
 * to derive the contract call payload. A bug in the padding here
 * silently sends funds to the WRONG address (a few zero bytes in the
 * wrong place can shift a 20-byte hash by a nibble) — pin every byte.
 */
class TronAbiTest extends TestCase
{
    /**
     * Real Tron mainnet address. Hash-20 derivation is straightforward
     * and well-known: Base58Check decode → drop the 0x41 version byte
     * + 4-byte checksum → 20-byte hash.
     */
    private const RECIPIENT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    public function test_encodes_address_with_12_zero_byte_pad(): void
    {
        // Solidity encoding: address occupies the LOW 20 bytes of a
        // 32-byte slot, padded with 12 leading zero bytes (24 hex
        // chars). The 20-byte hash itself is whatever
        // TronAddress::toHash20 returns.
        $expectedHash = TronAddress::toHash20(self::RECIPIENT);
        $param = TronAbi::encodeTransfer(self::RECIPIENT, 1);

        $this->assertSame(128, strlen($param), 'encoded parameter must be 64 hex chars (address) + 64 hex chars (uint256) = 128 chars');
        $this->assertSame(str_repeat('0', 24).$expectedHash, substr($param, 0, 64));
    }

    public function test_encodes_amount_as_big_endian_left_padded(): void
    {
        // 1_000_000 = 0xF4240 (5 hex chars). Slot is left-padded to 64
        // hex chars with zeros — byte 0 is the high byte (big-endian).
        $param = TronAbi::encodeTransfer(self::RECIPIENT, 1_000_000);
        $amountSlot = substr($param, 64, 64);

        $this->assertSame(str_pad('f4240', 64, '0', STR_PAD_LEFT), $amountSlot);
    }

    public function test_round_trips_a_realistic_usdt_amount(): void
    {
        // 1_500_000 USDT base units = 1.5 USDT. Pin the realistic case
        // because PHP's dechex on int is the load-bearing path.
        $param = TronAbi::encodeTransfer(self::RECIPIENT, 1_500_000);
        $amountSlot = substr($param, 64, 64);
        $this->assertSame((int) hexdec($amountSlot), 1_500_000);
    }

    public function test_throws_on_negative_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TronAbi::encodeTransfer(self::RECIPIENT, -1);
    }

    public function test_throws_on_invalid_address(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TronAbi::encodeTransfer('not-a-tron-address', 100);
    }
}
