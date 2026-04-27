<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\BotScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks the operator-visibility surface for the bot scoring engine on
 * the user edit page. Without this, an admin who sees `suspect` /
 * `likely_bot` in the user list has no way to drill into WHY — they'd
 * have to read raw `bot_scores` rows by hand.
 */
class UserResourceBotPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_no_score_row_renders_neutral_placeholders(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/users/'.$target->id.'/edit');

        $response->assertOk();
        $response->assertSeeText('trust (no eval yet)');
    }

    public function test_user_with_score_row_renders_tier_score_signal_breakdown(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $target = User::factory()->create(['username' => 'bob_inspected']);
        BotScore::create([
            'user_id' => $target->id,
            'score' => 0.45,
            'tier' => 'suspect',
            'signals' => [
                'shared_ip' => ['weight' => 0.15, 'raw' => 0.6, 'detail' => []],
                'response_time' => ['weight' => 0.20, 'raw' => 0.1, 'detail' => []],
            ],
            'last_evaluated_at' => Carbon::now()->subMinutes(5),
        ]);

        $response = $this->actingAs($admin)->get('/admin/users/'.$target->id.'/edit');

        $response->assertOk();
        // Tier and score are surfaced verbatim.
        $response->assertSeeText('suspect');
        $response->assertSeeText('0.450');
        // Per-signal breakdown shows weight + raw score together so the
        // operator can see which signal drove the verdict.
        $response->assertSeeText('shared_ip');
        $response->assertSeeText('response_time');
    }

    public function test_non_admin_cannot_reach_user_edit_page(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get('/admin/users/'.$user->id.'/edit');

        $this->assertContains($response->getStatusCode(), [302, 403, 404]);
    }
}
