<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\IpBlockEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the access contract for /admin/ip-block-entries:
 *   - non-admin users cannot reach the page
 *   - admins see the index and the seeded rows
 *
 * The CRUD round-trip (create with audit log + cache flush) is
 * exercised via the lower-level IpDenyListTest + IpBlockedTest pair —
 * Filament page tests would just re-test Filament's Livewire wiring.
 */
class IpBlockEntryResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get('/admin/ip-block-entries');

        $this->assertContains($response->getStatusCode(), [302, 403, 404]);
    }

    public function test_admin_lists_seeded_entries(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $entry = IpBlockEntry::create([
            'cidr' => '203.0.113.0/24',
            'note' => 'incident #42 — burst scrape on /shortlinks',
            'created_by_admin_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get('/admin/ip-block-entries');

        $response->assertOk();
        $response->assertSee($entry->cidr, false);
        $response->assertSee('incident #42', false);
    }
}
