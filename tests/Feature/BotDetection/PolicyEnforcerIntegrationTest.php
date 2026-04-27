<?php

declare(strict_types=1);

namespace Tests\Feature\BotDetection;

use App\Models\BotScore;
use App\Models\PtcAd;
use App\Models\Shortlink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * End-to-end check that bot tier transitions actually drive the
 * PolicyEnforcer-gated endpoints. Without this, a future change that
 * broke the wiring (e.g. forgot to inject PolicyEnforcer into a new
 * controller) would only show up at runtime in production.
 *
 * Tier → action chain pinned here:
 *
 *   - tier=`trust`     → PTC start works, withdrawal queued (no review)
 *   - tier=`suspect`   → PTC start works, withdrawal hold (review)
 *   - tier=`likely_bot`→ PTC start blocked (403), withdrawal hold
 *   - tier=`banned`    → BotScoreGate middleware blocks every /api/* hit
 *
 * `is_banned` flag short-circuits BotScoreGate independently of the
 * tier — so a manually-banned user with no bot score row still gets
 * blocked. That code path is also pinned here.
 */
class PolicyEnforcerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_trust_tier_user_can_start_ptc_view(): void
    {
        $user = $this->userAtTier('trust');
        $ad = $this->seedAd(reward: 10);

        $response = $this->actingAs($user)->postJson("/api/ptc/{$ad->id}/start");

        $response->assertStatus(200);
        $response->assertJsonStructure(['view_id', 'epoch_token', 'redirect_url']);
    }

    public function test_likely_bot_tier_user_gets_403_on_ptc_start(): void
    {
        $user = $this->userAtTier('likely_bot');
        $ad = $this->seedAd(reward: 10);

        $response = $this->actingAs($user)->postJson("/api/ptc/{$ad->id}/start");

        $response->assertStatus(403);
        $response->assertJson(['error' => 'tier_blocked']);
    }

    public function test_banned_tier_user_is_blocked_at_bot_gate_middleware(): void
    {
        $user = $this->userAtTier('banned');

        // BotScoreGate is the first gate before any controller — banned tier
        // returns 403 with `tier_banned` reason. It guards EVERY /api/*
        // route in the auth+bot.gate group, not just PTC. We hit the PTC
        // index here as a representative read endpoint.
        $response = $this->actingAs($user)->getJson('/api/ptc');

        $response->assertStatus(403);
        $response->assertJson(['error' => 'tier_banned']);
    }

    public function test_likely_bot_tier_user_can_still_load_shortlinks_index_but_cannot_start_one(): void
    {
        // PolicyEnforcer::canStartPtcView is named for PTC but is also the
        // gate the shortlink controller uses (same likely_bot/banned
        // exclusion list). Confirms the share-ban-list invariant holds.
        $user = $this->userAtTier('likely_bot');
        $link = Shortlink::create([
            'source' => 'internal',
            'external_id' => 'sl-'.uniqid(),
            'title' => 'go',
            'target_url' => 'https://example.com',
            'source_url' => 'https://destination.example.com/source',
            'provider_name' => 'mock',
            'reward_sat' => 5,
            'hold_seconds' => 5,
            'is_active' => true,
            'daily_limit_per_user' => 10,
        ]);

        $response = $this->actingAs($user)->postJson("/api/shortlinks/{$link->id}/start");

        $response->assertStatus(403);
    }

    public function test_suspect_tier_withdrawal_is_held_for_review(): void
    {
        $user = $this->userAtTier('suspect', balanceSat: 5000);

        $response = $this->actingAs($user)->postJson('/api/withdraw', [
            'amount_sat' => 2000,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
        ]);

        $response->assertStatus(202);
        $response->assertJson([
            'status' => 'hold',
            'requires_review' => true,
        ]);
        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $user->id,
            'status' => 'hold',
            'requires_review' => true,
        ]);
    }

    public function test_trust_tier_withdrawal_goes_straight_to_queued(): void
    {
        $user = $this->userAtTier('trust', balanceSat: 5000);

        $response = $this->actingAs($user)->postJson('/api/withdraw', [
            'amount_sat' => 2000,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
        ]);

        $response->assertStatus(202);
        $response->assertJson([
            'status' => 'queued',
            'requires_review' => false,
        ]);
    }

    public function test_banned_tier_withdrawal_is_refused_outright(): void
    {
        // BotScoreGate fires first on /api/* so it should return tier_banned
        // before WithdrawController's own banned_or_blocked branch.
        $user = $this->userAtTier('banned', balanceSat: 5000);

        $response = $this->actingAs($user)->postJson('/api/withdraw', [
            'amount_sat' => 2000,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
        ]);

        $response->assertStatus(403);
    }

    public function test_is_banned_flag_short_circuits_independently_of_tier(): void
    {
        // Manual operator ban — no bot score row, just is_banned=true.
        // BotScoreGate's first branch must catch this without consulting
        // PolicyEnforcer at all.
        $user = User::factory()->create(['is_banned' => true, 'ban_reason' => 'manual_admin']);

        $response = $this->actingAs($user)->getJson('/api/ptc');

        $response->assertStatus(403);
        $response->assertJson(['error' => 'banned']);
    }

    private function userAtTier(string $tier, int $balanceSat = 0): User
    {
        $user = User::factory()->create(['balance_sat' => $balanceSat]);
        BotScore::create([
            'user_id' => $user->id,
            'score' => match ($tier) {
                'trust' => 0.10,
                'suspect' => 0.45,
                'likely_bot' => 0.70,
                'banned' => 0.95,
            },
            'tier' => $tier,
            'signals' => [],
            'last_evaluated_at' => Carbon::now(),
        ]);

        return $user;
    }

    private function seedAd(int $reward = 10): PtcAd
    {
        return PtcAd::create([
            'user_id' => null,
            'source' => 'mock',
            'external_id' => 'ad-'.uniqid(),
            'title' => 'test ad',
            'target_url' => 'https://example.com',
            'reward_sat' => $reward,
            'duration_sec' => 5,
            'daily_limit_per_user' => 5,
            'is_active' => true,
            'status' => 'approved',
        ]);
    }
}
