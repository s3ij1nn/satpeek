<?php

declare(strict_types=1);

namespace Tests\Unit\BotDetection;

use App\BotDetection\IpAllowlist;
use Tests\TestCase;

/**
 * Locks the CIDR-aware match contract for the operator-supplied
 * shared-IP allowlist.
 */
class IpAllowlistTest extends TestCase
{
    public function test_empty_allowlist_never_matches(): void
    {
        $this->assertFalse(IpAllowlist::matches('203.0.113.10', []));
        $this->assertFalse(IpAllowlist::matches('203.0.113.10', ['']));
    }

    public function test_invalid_ip_returns_false_safely(): void
    {
        // A malformed IP must NOT accidentally match — false-by-default.
        $this->assertFalse(IpAllowlist::matches('not-an-ip', ['203.0.113.10']));
        $this->assertFalse(IpAllowlist::matches('', ['203.0.113.10']));
    }

    public function test_exact_ipv4_match(): void
    {
        $this->assertTrue(IpAllowlist::matches('203.0.113.10', ['203.0.113.10']));
        $this->assertFalse(IpAllowlist::matches('203.0.113.11', ['203.0.113.10']));
    }

    public function test_ipv4_cidr_match_at_byte_boundary(): void
    {
        // /24 — common campus / corporate prefix.
        $list = ['203.0.113.0/24'];
        $this->assertTrue(IpAllowlist::matches('203.0.113.0', $list));
        $this->assertTrue(IpAllowlist::matches('203.0.113.255', $list));
        $this->assertFalse(IpAllowlist::matches('203.0.114.0', $list));
        $this->assertFalse(IpAllowlist::matches('203.0.112.255', $list));
    }

    public function test_ipv4_cidr_match_at_bit_boundary(): void
    {
        // /20 — half a /16, requires the partial-byte mask logic.
        $list = ['10.16.0.0/20']; // covers 10.16.0.0–10.16.15.255
        $this->assertTrue(IpAllowlist::matches('10.16.0.0', $list));
        $this->assertTrue(IpAllowlist::matches('10.16.15.255', $list));
        $this->assertFalse(IpAllowlist::matches('10.16.16.0', $list));
        $this->assertFalse(IpAllowlist::matches('10.15.255.255', $list));
    }

    public function test_ipv4_slash_zero_matches_everything_in_family(): void
    {
        $list = ['0.0.0.0/0'];
        $this->assertTrue(IpAllowlist::matches('1.2.3.4', $list));
        $this->assertTrue(IpAllowlist::matches('203.0.113.10', $list));
        // But not IPv6 — different family.
        $this->assertFalse(IpAllowlist::matches('2001:db8::1', $list));
    }

    public function test_exact_ipv6_match(): void
    {
        $this->assertTrue(IpAllowlist::matches('2001:db8::1', ['2001:db8::1']));
        // Equivalent canonical form.
        $this->assertTrue(IpAllowlist::matches('2001:0db8:0000:0000:0000:0000:0000:0001', ['2001:db8::1']));
    }

    public function test_ipv6_cidr_match(): void
    {
        $list = ['2001:db8::/32'];
        $this->assertTrue(IpAllowlist::matches('2001:db8::1', $list));
        $this->assertTrue(IpAllowlist::matches('2001:db8:ffff:ffff::1', $list));
        $this->assertFalse(IpAllowlist::matches('2001:db9::1', $list));
    }

    public function test_garbage_entries_are_silently_skipped(): void
    {
        // Out-of-range bits, malformed prefix, non-numeric bits — all
        // skipped without throwing or false-positiving.
        $list = ['not-an-ip', '203.0.113.10/99', '203.0.113.10/abc', '/24', '203.0.113.10'];

        $this->assertTrue(IpAllowlist::matches('203.0.113.10', $list));
        $this->assertFalse(IpAllowlist::matches('203.0.113.11', $list));
    }

    public function test_mixed_family_in_list_does_not_cross_match(): void
    {
        $list = ['203.0.113.0/24', '2001:db8::/32'];

        $this->assertTrue(IpAllowlist::matches('203.0.113.5', $list));
        $this->assertTrue(IpAllowlist::matches('2001:db8::1', $list));
        $this->assertFalse(IpAllowlist::matches('198.51.100.5', $list));
        $this->assertFalse(IpAllowlist::matches('2001:db9::1', $list));
    }
}
