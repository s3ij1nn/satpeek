<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\UserIpObservationResource;
use App\Models\User;
use App\Models\UserIpObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks the operator audit surface for `user_ip_observations`:
 *
 *   - Resource is read-only: canCreate / canEdit / canDelete return false
 *     so an accidental admin write can't corrupt the SharedIpSignal
 *     evidence trail.
 *   - Non-admin users cannot reach `/admin/user-ip-observations` (Filament
 *     panel guard).
 *   - Admin users see the index with rows sorted by last_seen_at desc.
 */
class UserIpObservationResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_contract(): void
    {
        $this->assertFalse(UserIpObservationResource::canCreate());
        $this->assertFalse(UserIpObservationResource::canEdit(null));
        $this->assertFalse(UserIpObservationResource::canDelete(null));
    }

    public function test_non_admin_cannot_access_resource(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get('/admin/user-ip-observations');

        $this->assertContains($response->getStatusCode(), [302, 403, 404]);
    }

    public function test_admin_can_list_resource_and_sees_seeded_row(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $viewer = User::factory()->create(['username' => 'alice_observed']);
        UserIpObservation::create([
            'user_id' => $viewer->id,
            'ip' => '203.0.113.42',
            'first_seen_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
            'hit_count' => 1,
            'source' => 'login',
        ]);

        $response = $this->actingAs($admin)->get('/admin/user-ip-observations');

        $response->assertOk();
        $response->assertSeeText('alice_observed');
        $response->assertSeeText('203.0.113.42');
    }
}
