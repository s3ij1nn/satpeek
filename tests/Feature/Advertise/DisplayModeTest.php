<?php

namespace Tests\Feature\Advertise;

use App\Models\PtcAd;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the user-facing /advertise submission flow's display_mode contract:
 *   - default is `window` (matches the "safe everywhere" policy)
 *   - `iframe` is accepted when picked
 *   - unknown values are rejected by validation
 *   - the advertiser's own list / detail screen surface the chosen mode so they
 *     don't have to hit the Filament admin to verify which mode they picked
 */
class DisplayModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // ADS_AUTO_APPROVE doesn't matter for these assertions; the default
        // (false → pending_review) is fine.
        config()->set('satpeek.ads', [
            'commission_pct' => 25,
            'auto_approve' => true,
            'reward_min_sat' => 1,
            'reward_max_sat' => 100,
            'duration_min_sec' => 5,
            'duration_max_sec' => 120,
            'views_min' => 100,
            'views_max' => 1_000_000,
        ]);
    }

    public function test_store_defaults_display_mode_to_window_when_omitted(): void
    {
        $user = User::factory()->create(['balance_sat' => 50_000]);

        $response = $this->actingAs($user)->post(route('advertise.store'), $this->basePayload());

        $response->assertRedirect();
        $this->assertDatabaseHas('ptc_ads', [
            'user_id' => $user->id,
            'display_mode' => 'window',
        ]);
    }

    public function test_store_accepts_iframe_when_explicitly_chosen(): void
    {
        $user = User::factory()->create(['balance_sat' => 50_000]);

        $response = $this->actingAs($user)->post(
            route('advertise.store'),
            $this->basePayload(['display_mode' => 'iframe']),
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('ptc_ads', [
            'user_id' => $user->id,
            'display_mode' => 'iframe',
        ]);
    }

    public function test_store_rejects_unknown_display_mode(): void
    {
        $user = User::factory()->create(['balance_sat' => 50_000]);

        $response = $this->actingAs($user)->post(
            route('advertise.store'),
            $this->basePayload(['display_mode' => 'popup']),
        );

        $response->assertStatus(302); // redirect back with errors
        $response->assertSessionHasErrors('display_mode');
        $this->assertDatabaseMissing('ptc_ads', ['user_id' => $user->id]);
    }

    public function test_create_form_advertises_both_modes_with_window_preselected(): void
    {
        $user = User::factory()->create(['balance_sat' => 50_000]);

        $response = $this->actingAs($user)->get(route('advertise.create'));

        $response->assertOk();
        $response->assertSee('name="display_mode"', false);
        $response->assertSee('value="window"', false);
        $response->assertSee('value="iframe"', false);
        // The default-checked card must be `window`.
        $response->assertSee('value="window" checked', false);
    }

    public function test_advertiser_show_page_surfaces_display_mode_label(): void
    {
        $user = User::factory()->create(['balance_sat' => 50_000]);
        $ad = PtcAd::create([
            'user_id' => $user->id,
            'source' => 'user',
            'external_id' => 'user-test-iframe',
            'title' => 'Iframe Ad',
            'description' => null,
            'target_url' => 'https://my-own-site.example.com/landing',
            'display_mode' => 'iframe',
            'reward_sat' => 5,
            'cost_per_view_sat' => 7,
            'total_views_purchased' => 100,
            'views_remaining' => 100,
            'total_cost_sat' => 700,
            'duration_sec' => 10,
            'daily_limit_per_user' => 3,
            'is_active' => true,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($user)->get(route('advertise.show', ['id' => $ad->id]));

        $response->assertOk();
        $response->assertSee('Inline iframe');
    }

    /** @return array<string, mixed> */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'My affiliate offer',
            'description' => 'Test campaign',
            'target_url' => 'https://example.com/offer',
            'reward_sat' => 5,
            'duration_sec' => 15,
            'daily_limit_per_user' => 3,
            'total_views_purchased' => 100,
            'submission_notes' => null,
        ], $overrides);
    }
}
