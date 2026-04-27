<?php

namespace Tests\Feature\Advertise;

use App\Models\PtcAd;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the advertiser self-edit contract:
 *   - GET /advertise/{id}/edit is scoped to the owner
 *   - PATCH lets the advertiser change title, description, display_mode,
 *     daily_limit_per_user, and is_active
 *   - target_url, reward_sat, total_views_purchased, status are NOT
 *     editable by the user (admin-only or budget-locked)
 *   - pausing flips is_active to false but does NOT touch status
 */
class EditFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_form_renders_for_owner_only(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $ad = $this->seedAd(['user_id' => $owner->id]);

        $this->actingAs($owner)->get(route('advertise.edit', ['id' => $ad->id]))
            ->assertOk()
            ->assertSee('name="title"', false)
            ->assertSee('name="display_mode"', false)
            ->assertSee('name="daily_limit_per_user"', false);

        // A different user can't see (or hijack) the edit form.
        $this->actingAs($stranger)->get(route('advertise.edit', ['id' => $ad->id]))
            ->assertNotFound();
    }

    public function test_update_persists_editable_fields(): void
    {
        $owner = User::factory()->create();
        $ad = $this->seedAd([
            'user_id' => $owner->id,
            'title' => 'Old title',
            'description' => 'Old description',
            'display_mode' => 'window',
            'daily_limit_per_user' => 3,
            'is_active' => true,
        ]);

        $response = $this->actingAs($owner)->patch(route('advertise.update', ['id' => $ad->id]), [
            'title' => 'New title',
            'description' => 'New description',
            'display_mode' => 'iframe',
            'daily_limit_per_user' => 5,
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('advertise.show', ['id' => $ad->id]));
        $fresh = $ad->fresh();
        $this->assertSame('New title', $fresh->title);
        $this->assertSame('New description', $fresh->description);
        $this->assertSame('iframe', $fresh->display_mode);
        $this->assertSame(5, (int) $fresh->daily_limit_per_user);
        $this->assertTrue((bool) $fresh->is_active);
    }

    public function test_update_does_not_allow_changing_locked_fields(): void
    {
        $owner = User::factory()->create();
        $ad = $this->seedAd([
            'user_id' => $owner->id,
            'target_url' => 'https://locked-target.example.com/',
            'reward_sat' => 5,
            'cost_per_view_sat' => 7,
            'total_views_purchased' => 100,
            'views_remaining' => 100,
            'total_cost_sat' => 700,
            'status' => 'approved',
        ]);

        $this->actingAs($owner)->patch(route('advertise.update', ['id' => $ad->id]), [
            'title' => 'Edited',
            'description' => null,
            'display_mode' => 'window',
            'daily_limit_per_user' => 3,
            'is_active' => '1',
            // Tampered fields the form would never submit.
            'target_url' => 'https://hijacked.example.com/',
            'reward_sat' => 1000,
            'total_views_purchased' => 999999,
            'status' => 'completed',
        ]);

        $fresh = $ad->fresh();
        $this->assertSame('https://locked-target.example.com/', $fresh->target_url, 'target_url must not be writeable from the edit form');
        $this->assertSame(5, (int) $fresh->reward_sat);
        $this->assertSame(100, (int) $fresh->total_views_purchased);
        $this->assertSame('approved', $fresh->status, 'status is admin-controlled and must not flip via user PATCH');
    }

    public function test_update_pause_flips_is_active_without_touching_status(): void
    {
        $owner = User::factory()->create();
        $ad = $this->seedAd([
            'user_id' => $owner->id,
            'is_active' => true,
            'status' => 'approved',
        ]);

        // Browser submits is_active=0 from the hidden input when the checkbox is off.
        $this->actingAs($owner)->patch(route('advertise.update', ['id' => $ad->id]), [
            'title' => $ad->title,
            'description' => $ad->description,
            'display_mode' => $ad->display_mode,
            'daily_limit_per_user' => $ad->daily_limit_per_user,
            'is_active' => '0',
        ])->assertRedirect();

        $fresh = $ad->fresh();
        $this->assertFalse((bool) $fresh->is_active);
        $this->assertSame('approved', $fresh->status, 'pausing must keep approval intact for resume');
    }

    public function test_update_validates_display_mode_value(): void
    {
        $owner = User::factory()->create();
        $ad = $this->seedAd(['user_id' => $owner->id]);

        $this->actingAs($owner)->patch(route('advertise.update', ['id' => $ad->id]), [
            'title' => 'x',
            'description' => null,
            'display_mode' => 'popup',
            'daily_limit_per_user' => 3,
            'is_active' => '1',
        ])->assertSessionHasErrors('display_mode');
    }

    public function test_show_page_hides_edit_cta_for_terminal_states(): void
    {
        $owner = User::factory()->create();
        $rejected = $this->seedAd(['user_id' => $owner->id, 'status' => 'rejected', 'is_active' => false]);
        $completed = $this->seedAd(['user_id' => $owner->id, 'status' => 'completed', 'is_active' => false]);
        $approved  = $this->seedAd(['user_id' => $owner->id, 'status' => 'approved']);

        $this->actingAs($owner)
            ->get(route('advertise.show', ['id' => $rejected->id]))
            ->assertOk()
            ->assertDontSee(route('advertise.edit', ['id' => $rejected->id]));

        $this->actingAs($owner)
            ->get(route('advertise.show', ['id' => $completed->id]))
            ->assertOk()
            ->assertDontSee(route('advertise.edit', ['id' => $completed->id]));

        $this->actingAs($owner)
            ->get(route('advertise.show', ['id' => $approved->id]))
            ->assertOk()
            ->assertSee(route('advertise.edit', ['id' => $approved->id]), false);
    }

    private function seedAd(array $overrides = []): PtcAd
    {
        return PtcAd::create(array_merge([
            'user_id' => null,
            'source' => 'user',
            'external_id' => 'user-edit-'.uniqid(),
            'title' => 'Edit test ad',
            'description' => 'Seeded for EditFlowTest',
            'target_url' => 'https://example.com/destination',
            'display_mode' => 'window',
            'reward_sat' => 5,
            'cost_per_view_sat' => 7,
            'total_views_purchased' => 100,
            'views_remaining' => 100,
            'total_cost_sat' => 700,
            'duration_sec' => 10,
            'daily_limit_per_user' => 3,
            'is_active' => true,
            'status' => 'approved',
        ], $overrides));
    }
}
