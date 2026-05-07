<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\BotDetection\IpDenyList;
use App\Models\IpBlockEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Pins the global IpBlocked middleware contract:
 *   - Listed addresses get a 403 BEFORE any auth or controller runs
 *   - Non-listed addresses pass through unchanged
 *   - JSON requests get a structured error body; browser navigations
 *     get a plain "Forbidden." text response (the resource is
 *     intentionally bare so the deny-list mechanic itself isn't
 *     leaked back to the attacker)
 *   - The middleware reads request()->ip(), so X-Forwarded-For via
 *     trusted-proxy resolution is honoured automatically
 */
class IpBlockedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Stand up a probe route that the middleware will gate. /up
        // already exists but skipping middleware on health endpoints
        // is a common pattern, so we use a dedicated probe to avoid
        // surprises if /up's pipeline ever changes.
        Route::get('/__test/ip-block-probe', fn () => response()->json(['probe' => 'ok']));
    }

    public function test_unlisted_ip_passes_through(): void
    {
        $this->getJson('/__test/ip-block-probe', ['REMOTE_ADDR' => '203.0.113.10'])
            ->assertOk()
            ->assertExactJson(['probe' => 'ok']);
    }

    public function test_listed_ip_gets_403_with_json_body_for_ajax(): void
    {
        IpBlockEntry::create(['cidr' => '203.0.113.10']);
        IpDenyList::flush();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->getJson('/__test/ip-block-probe');

        $response->assertStatus(403);
        $response->assertExactJson(['error' => 'ip_blocked', 'reason' => 'operator_block']);
    }

    public function test_cidr_entry_blocks_whole_range(): void
    {
        IpBlockEntry::create(['cidr' => '203.0.113.0/24']);
        IpDenyList::flush();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->getJson('/__test/ip-block-probe')
            ->assertStatus(403);

        // Adjacent /24 still passes.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.114.42'])
            ->getJson('/__test/ip-block-probe')
            ->assertOk();
    }

    public function test_browser_navigation_gets_plain_text_403(): void
    {
        IpBlockEntry::create(['cidr' => '203.0.113.10']);
        IpDenyList::flush();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/__test/ip-block-probe', ['Accept' => 'text/html,*/*']);

        $response->assertStatus(403);
        $this->assertSame('Forbidden.', trim($response->getContent() ?: ''));
    }

    public function test_invalid_remote_addr_does_not_break_request(): void
    {
        // Defence-in-depth: even with a populated deny list, a malformed
        // REMOTE_ADDR must not cause the gate to incorrectly 403 (or
        // throw a 500). The probe should pass through cleanly.
        IpBlockEntry::create(['cidr' => '203.0.113.0/24']);
        IpDenyList::flush();

        $this->withServerVariables(['REMOTE_ADDR' => 'not-an-ip'])
            ->getJson('/__test/ip-block-probe')
            ->assertOk();
    }
}
