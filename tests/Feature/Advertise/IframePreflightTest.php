<?php

declare(strict_types=1);

namespace Tests\Feature\Advertise;

use App\Models\PtcAd;
use App\Models\User;
use App\Services\IframeEmbedProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the iframe-mode preflight integration:
 *
 *   - Submitting with display_mode=iframe to an X-Frame-Options=DENY
 *     destination flashes an `iframe_warning` session value AND the
 *     `/advertise/{id}` page surfaces it.
 *   - Window-mode submission is unaffected (no probe at all).
 *   - The probe verdict does NOT block submission — the campaign row
 *     is still created and (in test config) auto-approved.
 *   - Edit flow runs the probe ONLY when the advertiser is switching
 *     INTO iframe (a copy-only edit on an already-iframe ad doesn't
 *     re-probe).
 */
class IframePreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_iframe_submission_to_blocked_destination_creates_ad_with_warning(): void
    {
        $this->bindProbeStub(['embeddable' => false, 'blocker' => 'x_frame_options', 'detail' => 'Server sends X-Frame-Options: DENY']);

        $user = User::factory()->create(['balance_sat' => 100_000]);
        $response = $this->actingAs($user)->post(route('advertise.store'), $this->basePayload([
            'display_mode' => 'iframe',
        ]));

        // The campaign STILL submits — we don't hard-block the flow.
        $ad = PtcAd::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($ad);
        $this->assertSame('iframe', $ad->display_mode);

        $response->assertRedirect(route('advertise.show', ['id' => $ad->id]));
        $response->assertSessionHas('iframe_warning');
        $this->assertStringContainsString('X-Frame-Options: DENY', (string) session('iframe_warning'));
    }

    public function test_iframe_submission_to_clean_destination_has_no_warning(): void
    {
        $this->bindProbeStub(['embeddable' => true, 'blocker' => null, 'detail' => null]);

        $user = User::factory()->create(['balance_sat' => 100_000]);
        $response = $this->actingAs($user)->post(route('advertise.store'), $this->basePayload([
            'display_mode' => 'iframe',
        ]));

        $response->assertSessionMissing('iframe_warning');
    }

    public function test_window_mode_submission_skips_the_probe_entirely(): void
    {
        // Bind a stub that would FAIL the test if invoked — proves we
        // never call the probe for window mode (cost / latency saving).
        $this->app->bind(IframeEmbedProbe::class, function () {
            $this->fail('IframeEmbedProbe must not be invoked when display_mode is window');
        });

        $user = User::factory()->create(['balance_sat' => 100_000]);
        $this->actingAs($user)->post(route('advertise.store'), $this->basePayload([
            'display_mode' => 'window',
        ]))->assertRedirect();
    }

    public function test_edit_does_not_re_probe_when_already_iframe(): void
    {
        // Stub the probe to fail-the-test if invoked. The ad starts in
        // iframe mode and the edit only changes the title — the probe
        // is wasted work and we elide it.
        $this->app->bind(IframeEmbedProbe::class, function () {
            $this->fail('Probe must not run when display_mode is unchanged');
        });

        $user = User::factory()->create(['balance_sat' => 100_000]);
        $ad = PtcAd::create([
            'user_id' => $user->id,
            'source' => 'user',
            'external_id' => 'pre-existing-iframe',
            'title' => 'old title',
            'target_url' => 'https://example.com',
            'display_mode' => 'iframe',
            'reward_sat' => 5,
            'cost_per_view_sat' => 1,
            'total_views_purchased' => 100,
            'views_remaining' => 100,
            'total_cost_sat' => 100,
            'duration_sec' => 15,
            'daily_limit_per_user' => 3,
            'is_active' => true,
            'status' => 'approved',
        ]);

        $this->actingAs($user)->patch(route('advertise.update', ['id' => $ad->id]), [
            'title' => 'new title',
            'description' => null,
            'display_mode' => 'iframe',
            'daily_limit_per_user' => 3,
            'is_active' => 1,
        ])->assertRedirect();

        $this->assertSame('new title', $ad->fresh()->title);
    }

    public function test_edit_runs_probe_when_switching_from_window_to_iframe(): void
    {
        $this->bindProbeStub(['embeddable' => false, 'blocker' => 'csp_frame_ancestors', 'detail' => "Server sends Content-Security-Policy: frame-ancestors 'self'"]);

        $user = User::factory()->create(['balance_sat' => 100_000]);
        $ad = PtcAd::create([
            'user_id' => $user->id,
            'source' => 'user',
            'external_id' => 'edit-switch',
            'title' => 't',
            'target_url' => 'https://example.com',
            'display_mode' => 'window',
            'reward_sat' => 5,
            'cost_per_view_sat' => 1,
            'total_views_purchased' => 100,
            'views_remaining' => 100,
            'total_cost_sat' => 100,
            'duration_sec' => 15,
            'daily_limit_per_user' => 3,
            'is_active' => true,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($user)->patch(route('advertise.update', ['id' => $ad->id]), [
            'title' => 't',
            'description' => null,
            'display_mode' => 'iframe',
            'daily_limit_per_user' => 3,
            'is_active' => 1,
        ]);

        $this->assertSame('iframe', $ad->fresh()->display_mode);
        $response->assertSessionHas('iframe_warning');
    }

    /**
     * @param  array{embeddable: bool, blocker: ?string, detail: ?string}  $verdict
     */
    private function bindProbeStub(array $verdict): void
    {
        $this->app->bind(IframeEmbedProbe::class, function () use ($verdict) {
            return new class($verdict) extends IframeEmbedProbe
            {
                /** @param array{embeddable: bool, blocker: ?string, detail: ?string} $verdict */
                public function __construct(private readonly array $verdict)
                {
                    // Skip parent — no HttpFactory needed on the test path.
                }

                public function probe(string $url, int $timeoutSeconds = 5): array
                {
                    return $this->verdict;
                }
            };
        });
    }

    /** @return array<string, mixed> */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Iframe campaign',
            'description' => null,
            'target_url' => 'https://example.com/offer',
            'reward_sat' => 5,
            'duration_sec' => 15,
            'daily_limit_per_user' => 3,
            'total_views_purchased' => 100,
            'submission_notes' => null,
        ], $overrides);
    }
}
