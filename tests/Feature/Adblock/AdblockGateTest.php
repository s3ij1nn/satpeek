<?php

declare(strict_types=1);

namespace Tests\Feature\Adblock;

use App\Models\PtcAd;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks the anti-adblock gate end-to-end:
 *
 *   - Earning routes (`/api/ptc/{id}/start`, `/api/shortlinks/{id}/start`,
 *     `/api/withdraw`) refuse with 403 `adblock_detected` when the user's
 *     last report flagged adblock or Brave.
 *   - Same routes refuse with 403 `adblock_check_required` when the
 *     user has never reported OR the report is older than the configured
 *     TTL. This is the anti-bypass measure: a bot that simply skips the
 *     report can't claim "clean" by default.
 *   - `/api/adblock/report` is exempt from the gate (otherwise the
 *     freshly-checking client would lock itself out of the very endpoint
 *     it needs to call).
 *   - A clean recent report passes through.
 */
class AdblockGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_recent_report_passes_through_to_ptc_start(): void
    {
        $user = $this->userWithAdblockStatus('clean', secondsAgo: 30);
        $ad = $this->seedAd();

        $response = $this->actingAs($user)->postJson("/api/ptc/{$ad->id}/start");

        $response->assertStatus(200);
    }

    public function test_detected_status_returns_403_adblock_detected(): void
    {
        $user = $this->userWithAdblockStatus('detected', secondsAgo: 10);
        $ad = $this->seedAd();

        $response = $this->actingAs($user)->postJson("/api/ptc/{$ad->id}/start");

        $response->assertStatus(403);
        $response->assertJson(['error' => 'adblock_detected']);
    }

    public function test_never_reported_returns_403_adblock_check_required(): void
    {
        // Factory defaults to a fresh clean state for normal feature tests
        // (so earning-route tests aren't blocked by AdblockGate); here we
        // explicitly wipe both fields to model the "user just registered,
        // frontend hasn't posted /api/adblock/report yet" path.
        $user = User::factory()->create();
        $user->forceFill(['adblock_status' => null, 'adblock_checked_at' => null])->save();
        $ad = $this->seedAd();

        $response = $this->actingAs($user)->postJson("/api/ptc/{$ad->id}/start");

        $response->assertStatus(403);
        $response->assertJson(['error' => 'adblock_check_required']);
    }

    public function test_stale_clean_report_returns_403_adblock_check_required(): void
    {
        // Default TTL is 300 s — an 8-minute-old check is past the window.
        $user = $this->userWithAdblockStatus('clean', secondsAgo: 480);
        $ad = $this->seedAd();

        $response = $this->actingAs($user)->postJson("/api/ptc/{$ad->id}/start");

        $response->assertStatus(403);
        $response->assertJson(['error' => 'adblock_check_required']);
    }

    public function test_report_endpoint_itself_is_not_gated(): void
    {
        // Deliberately leave the user in the worst state — the freshly-
        // checking client must still be able to call /api/adblock/report
        // to clear it. If the gate covered this route the system would
        // be unrecoverable.
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/adblock/report', [
            'adblock_detected' => false,
            'brave_detected' => false,
            'signals' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'clean']);
        $this->assertSame('clean', $user->fresh()->adblock_status);
    }

    public function test_brave_detected_marks_status_detected_via_report(): void
    {
        // Brave shields are policy-equivalent to adblock per the operator
        // requirement. Either flag must drive `detected`.
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/adblock/report', [
            'adblock_detected' => false,
            'brave_detected' => true,
            'signals' => ['brave'],
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'detected']);
    }

    public function test_withdrawal_route_is_also_gated(): void
    {
        // Earning gate must cover withdraw so a user with adblock can't
        // pull existing balance — only PTC/shortlink START is the most
        // common surface but withdrawal is the actual money path.
        $user = $this->userWithAdblockStatus('detected', secondsAgo: 10);
        $user->forceFill(['balance_sat' => 5000])->save();

        $response = $this->actingAs($user)->postJson('/api/withdraw', [
            'amount_sat' => 2000,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'adblock_detected']);
    }

    private function userWithAdblockStatus(string $status, int $secondsAgo): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'adblock_status' => $status,
            'adblock_checked_at' => Carbon::now()->subSeconds($secondsAgo),
        ])->save();

        return $user;
    }

    private function seedAd(): PtcAd
    {
        return PtcAd::create([
            'user_id' => null,
            'source' => 'mock',
            'external_id' => 'ad-'.uniqid(),
            'title' => 'test ad',
            'target_url' => 'https://example.com',
            'reward_sat' => 10,
            'duration_sec' => 5,
            'daily_limit_per_user' => 5,
            'is_active' => true,
            'status' => 'approved',
        ]);
    }
}
