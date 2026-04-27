<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\PtcViewResource;
use App\Filament\Resources\ShortlinkClickResource;
use App\Models\PtcAd;
use App\Models\PtcView;
use App\Models\Shortlink;
use App\Models\ShortlinkClick;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks the access contract for the read-only debug resources:
 *   - non-admin users cannot reach /admin/* (Filament's panel guard)
 *   - admin users see the index page and find seeded rows
 *   - rejecting POST/PATCH/DELETE attempts is enforced via canCreate/Edit/Delete
 *     returning false on the Resource — covered by GET-only tests here
 *     plus contract assertions in unit-style checks.
 */
class DebugResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_ptc_views_resource(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get('/admin/ptc-views');

        // Filament redirects unauthorised users to /admin/login.
        $this->assertContains($response->getStatusCode(), [302, 403, 404]);
    }

    public function test_admin_can_list_ptc_views_resource(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $view = $this->seedPtcView();

        $response = $this->actingAs($admin)->get('/admin/ptc-views');

        $response->assertOk();
        // The Filament Livewire table is server-rendered for the first page,
        // so the seeded ad title must appear in the HTML.
        $response->assertSee($view->ad->title, false);
    }

    public function test_non_admin_cannot_access_shortlink_clicks_resource(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get('/admin/shortlink-clicks');

        $this->assertContains($response->getStatusCode(), [302, 403, 404]);
    }

    public function test_admin_can_list_shortlink_clicks_resource(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $click = $this->seedShortlinkClick();

        $response = $this->actingAs($admin)->get('/admin/shortlink-clicks');

        $response->assertOk();
        $response->assertSee($click->shortlink->title, false);
    }

    public function test_resources_forbid_create_edit_delete_via_filament_helpers(): void
    {
        // Defence-in-depth at the Filament Resource layer — the canCreate /
        // canEdit / canDelete static methods short-circuit any UI affordance.
        // We don't render the routes (Filament doesn't even register them
        // when getPages() omits create/edit), but pinning the policy here
        // means a future refactor that re-adds those pages must explicitly
        // flip these flags.
        $this->assertFalse(PtcViewResource::canCreate());
        $this->assertFalse(PtcViewResource::canEdit(new PtcView));
        $this->assertFalse(PtcViewResource::canDelete(new PtcView));

        $this->assertFalse(ShortlinkClickResource::canCreate());
        $this->assertFalse(ShortlinkClickResource::canEdit(new ShortlinkClick));
        $this->assertFalse(ShortlinkClickResource::canDelete(new ShortlinkClick));
    }

    private function seedPtcView(): PtcView
    {
        $ad = PtcAd::create([
            'user_id' => null,
            'source' => 'mock',
            'external_id' => 'debug-ad-'.uniqid(),
            'title' => 'Debug PTC ad',
            'description' => null,
            'target_url' => 'https://example.com/ad',
            'display_mode' => 'window',
            'reward_sat' => 5,
            'cost_per_view_sat' => 0,
            'total_views_purchased' => 0,
            'views_remaining' => 0,
            'total_cost_sat' => 0,
            'duration_sec' => 5,
            'daily_limit_per_user' => 5,
            'is_active' => true,
            'status' => 'approved',
        ]);
        $viewer = User::factory()->create();

        return PtcView::create([
            'user_id' => $viewer->id,
            'ptc_ad_id' => $ad->id,
            'epoch_token' => 'pv_'.bin2hex(random_bytes(14)),
            'status' => 'pending',
            'started_at' => Carbon::now(),
            'heartbeats_received' => 0,
            'heartbeats_expected' => 3,
        ]);
    }

    private function seedShortlinkClick(): ShortlinkClick
    {
        $link = Shortlink::create([
            'source' => 'internal',
            'external_id' => 'debug-sl-'.uniqid(),
            'title' => 'Debug shortlink',
            'target_url' => 'https://example.com/destination',
            'source_url' => 'https://destination.example.com/source',
            'provider_name' => 'mock',
            'reward_sat' => 5,
            'hold_seconds' => 5,
            'daily_limit_per_user' => 5,
            'is_active' => true,
        ]);
        $clicker = User::factory()->create();

        return ShortlinkClick::create([
            'user_id' => $clicker->id,
            'shortlink_id' => $link->id,
            'epoch_token' => 'sc_'.bin2hex(random_bytes(14)),
            'status' => 'pending',
            'started_at' => Carbon::now(),
        ]);
    }
}
