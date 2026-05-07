<?php

declare(strict_types=1);

namespace Tests\Unit\BotDetection;

use App\BotDetection\IpDenyList;
use App\Models\IpBlockEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Pins the deny-list service contract:
 *   - Empty list never blocks
 *   - Exact-IP and CIDR entries both match through the shared
 *     IpAllowlist algorithm
 *   - Cache returns the same answer until flush() is called, even
 *     when the underlying table changes
 *   - flush() invalidates the cache so an operator's add takes
 *     effect on the very next request
 */
class IpDenyListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_empty_list_never_blocks(): void
    {
        $this->assertFalse(IpDenyList::blocks('203.0.113.10'));
        $this->assertFalse(IpDenyList::blocks('2001:db8::1'));
    }

    public function test_exact_ipv4_entry_blocks_only_that_address(): void
    {
        IpBlockEntry::create(['cidr' => '203.0.113.10']);
        IpDenyList::flush();

        $this->assertTrue(IpDenyList::blocks('203.0.113.10'));
        $this->assertFalse(IpDenyList::blocks('203.0.113.11'));
    }

    public function test_cidr_entry_blocks_whole_range(): void
    {
        IpBlockEntry::create(['cidr' => '203.0.113.0/24']);
        IpDenyList::flush();

        $this->assertTrue(IpDenyList::blocks('203.0.113.0'));
        $this->assertTrue(IpDenyList::blocks('203.0.113.255'));
        $this->assertFalse(IpDenyList::blocks('203.0.114.0'));
    }

    public function test_invalid_ip_does_not_block(): void
    {
        // Defence-in-depth: a parser bug somewhere upstream must NEVER
        // auto-403 the request. False-by-default on garbage input.
        IpBlockEntry::create(['cidr' => '203.0.113.0/24']);
        IpDenyList::flush();

        $this->assertFalse(IpDenyList::blocks('not-an-ip'));
        $this->assertFalse(IpDenyList::blocks(''));
    }

    public function test_cache_returns_stale_answer_until_flush(): void
    {
        IpBlockEntry::create(['cidr' => '203.0.113.10']);
        IpDenyList::flush();
        // Prime cache with the current state.
        $this->assertTrue(IpDenyList::blocks('203.0.113.10'));

        // Mutate the DB without busting the cache — the service must
        // still report the cached answer.
        IpBlockEntry::query()->delete();
        $this->assertTrue(
            IpDenyList::blocks('203.0.113.10'),
            'cache should still report blocked until flush() runs'
        );

        // Operator action triggers the flush; next call sees the new state.
        IpDenyList::flush();
        $this->assertFalse(IpDenyList::blocks('203.0.113.10'));
    }
}
