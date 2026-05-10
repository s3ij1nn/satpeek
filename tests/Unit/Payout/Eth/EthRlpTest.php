<?php

declare(strict_types=1);

namespace Tests\Unit\Payout\Eth;

use App\Payout\Eth\EthRlp;
use PHPUnit\Framework\TestCase;

/**
 * Pins the RLP encoder against the canonical Ethereum test vectors
 * (https://eth.wiki/fundamentals/rlp). A bug in leading-zero
 * stripping or length-of-length encoding silently produces a
 * different signing hash and node rejection — every byte position
 * matters.
 */
class EthRlpTest extends TestCase
{
    public function test_encodes_zero_uint_as_empty_byte(): void
    {
        // RLP encodes 0 as the empty byte string, NOT as 0x00.
        // 0x00 would be the byte string "\x00" (encoded as itself),
        // which is a DIFFERENT value semantically.
        $this->assertSame("\x80", EthRlp::encodeUint('0'));
    }

    public function test_encodes_single_byte_under_0x80_as_itself(): void
    {
        // 1 → 0x01 (single byte < 0x80, no length prefix)
        $this->assertSame("\x01", EthRlp::encodeUint('1'));
        $this->assertSame("\x7f", EthRlp::encodeUint('127'));
    }

    public function test_encodes_short_integer_with_length_prefix(): void
    {
        // 128 → 0x81 0x80 (1-byte string, 0x80 + len)
        $this->assertSame("\x81\x80", EthRlp::encodeUint('128'));
        // 1024 → 0x82 0x04 0x00 (2-byte big-endian, leading-zero-stripped)
        $this->assertSame("\x82\x04\x00", EthRlp::encodeUint('1024'));
    }

    public function test_encodes_large_uint_via_gmp(): void
    {
        // 1 ETH = 1e18 wei. Doesn't overflow int64 (PHP_INT_MAX ≈ 9.2e18)
        // but uses gmp under the hood. Pin the hex output.
        $oneEth = '1000000000000000000';
        $encoded = EthRlp::encodeUint($oneEth);
        // 1e18 = 0x0DE0B6B3A7640000 — 8 bytes, leading zero NOT
        // stripped (0x0d is fine). 0x88 + 0x0d e0 b6 b3 a7 64 00 00.
        $this->assertSame("\x88\x0d\xe0\xb6\xb3\xa7\x64\x00\x00", $encoded);
    }

    public function test_encodes_empty_string(): void
    {
        $this->assertSame("\x80", EthRlp::encodeBytes(''));
    }

    public function test_encodes_short_string(): void
    {
        // "dog" → 0x83 'd' 'o' 'g'
        $this->assertSame("\x83dog", EthRlp::encodeBytes('dog'));
    }

    public function test_encodes_55_byte_string_uses_short_form(): void
    {
        $bytes = str_repeat('a', 55);
        $encoded = EthRlp::encodeBytes($bytes);
        $this->assertSame(56, strlen($encoded));
        // 0x80 + 55 = 0xb7 — the LAST short-form prefix byte.
        $this->assertSame("\xb7", $encoded[0]);
    }

    public function test_encodes_56_byte_string_uses_long_form(): void
    {
        $bytes = str_repeat('a', 56);
        $encoded = EthRlp::encodeBytes($bytes);
        // 0xb7 + 1 (len-of-len) = 0xb8, then 56 = 0x38, then 56 bytes.
        $this->assertSame("\xb8\x38".$bytes, $encoded);
    }

    public function test_encodes_empty_list(): void
    {
        $this->assertSame("\xc0", EthRlp::encodeList([]));
    }

    public function test_encodes_list_of_short_strings(): void
    {
        // ["cat", "dog"] = 0xc8 0x83 'c' 'a' 't' 0x83 'd' 'o' 'g'
        // total payload = 8 bytes → 0xc0 + 8 = 0xc8
        $encoded = EthRlp::encodeList([
            EthRlp::encodeBytes('cat'),
            EthRlp::encodeBytes('dog'),
        ]);
        $this->assertSame("\xc8\x83cat\x83dog", $encoded);
    }

    public function test_rejects_non_decimal_uint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EthRlp::encodeUint('abc');
    }
}
