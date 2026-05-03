<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\PtcAdResource\Pages\ListPtcAds;
use App\Models\PtcAd;
use App\Models\User;
use App\Services\IframeEmbedProbe;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Locks the operator-side `Test embed` row action on PtcAdResource.
 *
 * The probe semantics themselves are exhaustively covered by
 * IframeEmbedProbeTest; this test fixates the Filament wiring:
 *
 *   - the action runs against an iframe-mode ad and produces the
 *     expected success/danger Notification based on the probe verdict
 *   - we don't try to pin visibility-only rows (window-mode) here
 *     because that's a render-layer concern that requires a much
 *     heavier livewire+filament harness for marginal coverage gain
 */
class PtcAdResourceIframeProbeActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_iframe_action_runs_probe_and_emits_success_for_clean_destination(): void
    {
        $this->bindProbeStub(['embeddable' => true, 'blocker' => null, 'detail' => null]);

        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $ad = $this->seedAd();

        Livewire::actingAs($admin)
            ->test(ListPtcAds::class)
            ->callTableAction('test_iframe', $ad->id)
            ->assertHasNoTableActionErrors();

        Notification::assertNotified('Embeddable');
    }

    public function test_test_iframe_action_emits_danger_when_destination_blocks(): void
    {
        $this->bindProbeStub([
            'embeddable' => false,
            'blocker' => 'x_frame_options',
            'detail' => 'Server sends X-Frame-Options: DENY',
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $ad = $this->seedAd();

        Livewire::actingAs($admin)
            ->test(ListPtcAds::class)
            ->callTableAction('test_iframe', $ad->id)
            ->assertHasNoTableActionErrors();

        Notification::assertNotified('Not embeddable');
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

    private function seedAd(): PtcAd
    {
        return PtcAd::create([
            'source' => 'internal',
            'external_id' => 'iframe-probe-'.uniqid(),
            'title' => 'Probe target',
            'target_url' => 'https://example.com',
            'display_mode' => 'iframe',
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
    }
}
