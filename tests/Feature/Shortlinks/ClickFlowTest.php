<?php

namespace Tests\Feature\Shortlinks;

use App\BotDetection\PolicyEnforcer;
use App\Captcha\TrajectoryTraceProvider;
use App\Models\BotScore;
use App\Models\CaptchaChallenge;
use App\Models\Shortlink;
use App\Models\ShortlinkClick;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks the ouo.io-style shortlink interstitial flow:
 *   - list returns active links with their hold time + reward
 *   - start hands the viewer the target URL + a hold_seconds budget
 *   - completing the hold credits balance + writes the ledger row
 *   - the abuse guards (daily limit, tier gate, too-fast, token mismatch,
 *     replayed completion) fire so a viewer can't bypass the wait
 */
class ClickFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_only_active_shortlinks(): void
    {
        $user = User::factory()->create();
        $live = $this->seedLink(['title' => 'Live link']);
        $disabled = $this->seedLink(['title' => 'Off link', 'is_active' => false]);

        $response = $this->actingAs($user)->getJson('/api/shortlinks');

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('Live link', $titles);
        $this->assertNotContains('Off link', $titles);
    }

    public function test_start_returns_redirect_url_and_hold_seconds(): void
    {
        $user = User::factory()->create();
        $link = $this->seedLink([
            'target_url' => 'https://example.com/landing',
            'hold_seconds' => 8,
            'reward_sat' => 3,
        ]);

        $response = $this->actingAs($user)->postJson("/api/shortlinks/{$link->id}/start");

        $response->assertOk();
        $response->assertJson([
            'redirect_url' => 'https://example.com/landing',
            'hold_seconds' => 8,
        ]);
        $this->assertDatabaseHas('shortlink_clicks', [
            'user_id' => $user->id,
            'shortlink_id' => $link->id,
            'status' => 'pending',
        ]);
    }

    public function test_start_blocks_when_user_tier_is_likely_bot(): void
    {
        $user = User::factory()->create();
        BotScore::create([
            'user_id' => $user->id,
            'score' => 0.72,
            'tier' => 'likely_bot',
            'signals' => [],
        ]);
        // Sanity: the policy classifies them as blocked.
        $this->assertFalse(app(PolicyEnforcer::class)->canStartPtcView($user->fresh()));

        $link = $this->seedLink();

        $response = $this->actingAs($user)->postJson("/api/shortlinks/{$link->id}/start");

        $response->assertStatus(403);
        $response->assertJson(['error' => 'tier_blocked']);
        $this->assertDatabaseMissing('shortlink_clicks', ['user_id' => $user->id]);
    }

    public function test_start_returns_429_when_daily_limit_reached(): void
    {
        $user = User::factory()->create();
        $link = $this->seedLink(['daily_limit_per_user' => 2]);
        // Two prior verified clicks today exhaust the daily quota.
        for ($i = 0; $i < 2; $i++) {
            ShortlinkClick::create([
                'user_id' => $user->id,
                'shortlink_id' => $link->id,
                'epoch_token' => 'sc_used_'.$i.'_'.uniqid(),
                'status' => 'verified',
                'started_at' => Carbon::now()->subMinutes($i + 1),
                'completed_at' => Carbon::now()->subMinutes($i + 1)->addSeconds($link->hold_seconds),
            ]);
        }

        $response = $this->actingAs($user)->postJson("/api/shortlinks/{$link->id}/start");

        $response->assertStatus(429);
        $response->assertJson(['error' => 'daily_limit_reached']);
    }

    public function test_complete_credits_balance_after_full_hold(): void
    {
        $user = User::factory()->create(['balance_sat' => 0, 'total_earned_sat' => 0]);
        $link = $this->seedLink(['hold_seconds' => 5, 'reward_sat' => 11]);

        $start = $this->actingAs($user)->postJson("/api/shortlinks/{$link->id}/start")->json();
        // Backdate started_at so the elapsed-vs-hold check sees a full wait.
        ShortlinkClick::where('id', $start['click_id'])->update([
            'started_at' => Carbon::now()->subSeconds($link->hold_seconds + 2),
        ]);

        $challenge = $this->seedChallenge();
        $challenge->update(['status' => 'verified']);

        $response = $this->actingAs($user)->postJson("/api/shortlinks/{$start['click_id']}/complete", [
            'epoch_token' => $start['epoch_token'],
            'captcha_challenge_id' => $challenge->challenge_id,
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'reward_sat' => 11]);
        $this->assertSame(11, (int) $user->fresh()->balance_sat);
        $this->assertSame(11, (int) $user->fresh()->total_earned_sat);
        $this->assertSame('verified', ShortlinkClick::find($start['click_id'])->status);
        $this->assertDatabaseHas('balance_ledgers', [
            'user_id' => $user->id,
            'delta_sat' => 11,
            'reason' => 'shortlink',
        ]);
    }

    public function test_complete_rejects_when_hold_too_fast(): void
    {
        $user = User::factory()->create(['balance_sat' => 0]);
        $link = $this->seedLink(['hold_seconds' => 30]);

        $start = $this->actingAs($user)->postJson("/api/shortlinks/{$link->id}/start")->json();
        // started_at left at "now" → elapsed ~ 0 << hold_seconds.

        $challenge = $this->seedChallenge();
        $challenge->update(['status' => 'verified']);

        $response = $this->actingAs($user)->postJson("/api/shortlinks/{$start['click_id']}/complete", [
            'epoch_token' => $start['epoch_token'],
            'captcha_challenge_id' => $challenge->challenge_id,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'too_fast']);
        $this->assertSame(0, (int) $user->fresh()->balance_sat);
        $this->assertSame('rejected', ShortlinkClick::find($start['click_id'])->status);
    }

    public function test_complete_rejects_on_epoch_token_mismatch(): void
    {
        $user = User::factory()->create(['balance_sat' => 0]);
        $link = $this->seedLink(['hold_seconds' => 5]);

        $start = $this->actingAs($user)->postJson("/api/shortlinks/{$link->id}/start")->json();
        ShortlinkClick::where('id', $start['click_id'])->update([
            'started_at' => Carbon::now()->subSeconds($link->hold_seconds + 2),
        ]);
        $challenge = $this->seedChallenge();
        $challenge->update(['status' => 'verified']);

        $response = $this->actingAs($user)->postJson("/api/shortlinks/{$start['click_id']}/complete", [
            'epoch_token' => 'sc_FORGED_TOKEN_xxx',
            'captcha_challenge_id' => $challenge->challenge_id,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'token_mismatch']);
        $this->assertSame(0, (int) $user->fresh()->balance_sat);
        // Click stays pending — token mismatch alone shouldn't burn the slot.
        $this->assertSame('pending', ShortlinkClick::find($start['click_id'])->status);
    }

    public function test_complete_cannot_be_replayed_after_verification(): void
    {
        $user = User::factory()->create(['balance_sat' => 0]);
        $link = $this->seedLink(['hold_seconds' => 5, 'reward_sat' => 7]);

        $start = $this->actingAs($user)->postJson("/api/shortlinks/{$link->id}/start")->json();
        ShortlinkClick::where('id', $start['click_id'])->update([
            'started_at' => Carbon::now()->subSeconds($link->hold_seconds + 2),
        ]);
        $challenge = $this->seedChallenge();
        $challenge->update(['status' => 'verified']);

        $first = $this->actingAs($user)->postJson("/api/shortlinks/{$start['click_id']}/complete", [
            'epoch_token' => $start['epoch_token'],
            'captcha_challenge_id' => $challenge->challenge_id,
        ]);
        $first->assertOk();
        $this->assertSame(7, (int) $user->fresh()->balance_sat);

        $replay = $this->actingAs($user)->postJson("/api/shortlinks/{$start['click_id']}/complete", [
            'epoch_token' => $start['epoch_token'],
            'captcha_challenge_id' => $challenge->challenge_id,
        ]);

        $replay->assertStatus(422);
        $replay->assertJson(['error' => 'click_not_pending']);
        // Balance must not double-credit on replay.
        $this->assertSame(7, (int) $user->fresh()->balance_sat);
    }

    private function seedLink(array $overrides = []): Shortlink
    {
        return Shortlink::create(array_merge([
            'source' => 'internal',
            'external_id' => 'sl-'.uniqid(),
            'title' => 'Test shortlink',
            'target_url' => 'https://destination.example.com/',
            'reward_sat' => 5,
            'hold_seconds' => 10,
            'daily_limit_per_user' => 5,
            'is_active' => true,
        ], $overrides));
    }

    private function seedChallenge(): CaptchaChallenge
    {
        $shape = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 40, 2, 8000, 60);
        $issuedAt = Carbon::now()->subSeconds(3);

        return CaptchaChallenge::create([
            'challenge_id' => 'cc_test_'.uniqid(),
            'user_id' => null,
            'session_id' => 'test',
            'provider' => 'trajectory_trace',
            'seed' => 'test-seed',
            'expected_shape' => $shape,
            'fingerprint_hash' => null,
            'client_ip' => '127.0.0.1',
            'ja4' => null,
            'user_agent' => 'phpunit',
            'status' => 'issued',
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt->copy()->addSeconds(60),
        ]);
    }
}
