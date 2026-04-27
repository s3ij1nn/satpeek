<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\BotScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks the dashboard tier-banner contract:
 *
 *   - trust users see no banner (the dashboard stays clean for the
 *     overwhelming majority of legitimate users)
 *   - suspect / likely_bot / banned users each see a tier-specific
 *     message in plain language, so a legit user hit by a shared-NAT
 *     false positive can self-diagnose and a real bot operator gets a
 *     clear "you've been caught" signal
 *   - is_banned flag (manual operator ban) shows the suspended banner
 *     even when the bot_scores row is missing
 */
class TierBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_trust_user_sees_no_banner(): void
    {
        $user = $this->userAtTier('trust');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSeeText('Heads up:');
        $response->assertDontSeeText('PTC paused.');
        $response->assertDontSeeText('Account suspended.');
    }

    public function test_user_with_no_bot_score_row_sees_no_banner(): void
    {
        $user = User::factory()->create();
        // No BotScore row at all — dashboard.blade falls back to 'trust'.

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSeeText('Heads up:');
        $response->assertDontSeeText('PTC paused.');
        $response->assertDontSeeText('Account suspended.');
    }

    public function test_suspect_user_sees_warning_banner(): void
    {
        $user = $this->userAtTier('suspect');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSeeText('Heads up:');
        $response->assertSeeText('shared wifi');
    }

    public function test_likely_bot_user_sees_ptc_paused_banner(): void
    {
        $user = $this->userAtTier('likely_bot');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSeeText('PTC paused.');
        $response->assertSeeText('reassesses');
    }

    public function test_banned_tier_user_sees_suspension_banner(): void
    {
        $user = $this->userAtTier('banned');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSeeText('Account suspended.');
    }

    public function test_manually_banned_user_with_no_score_row_still_sees_banner(): void
    {
        $user = User::factory()->create([
            'is_banned' => true,
            'ban_reason' => 'manual_admin_action',
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSeeText('Account suspended.');
        $response->assertSee('manual_admin_action', false);
    }

    private function userAtTier(string $tier): User
    {
        $user = User::factory()->create();
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
}
