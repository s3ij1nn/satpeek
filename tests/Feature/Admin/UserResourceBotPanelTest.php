<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\BotScore;
use App\Models\User;
use App\Models\UserIpObservation;
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

    public function test_recent_ip_history_renders_inline_with_sibling_count(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $target = User::factory()->create(['username' => 'carol_targeted']);

        // Target has been on two IPs, one shared with another user.
        UserIpObservation::create([
            'user_id' => $target->id,
            'ip' => '203.0.113.10',
            'first_seen_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
            'hit_count' => 5,
            'source' => 'login',
        ]);
        UserIpObservation::create([
            'user_id' => $target->id,
            'ip' => '198.51.100.20',
            'first_seen_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
            'hit_count' => 1,
            'source' => 'register',
        ]);
        // Sibling on 203.0.113.10 — siblings count for that row should = 1.
        UserIpObservation::create([
            'user_id' => User::factory()->create()->id,
            'ip' => '203.0.113.10',
            'first_seen_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
            'hit_count' => 1,
            'source' => 'login',
        ]);

        $response = $this->actingAs($admin)->get('/admin/users/'.$target->id.'/edit');

        $response->assertOk();
        // Both IPs and the sources are surfaced.
        $response->assertSeeText('203.0.113.10');
        $response->assertSeeText('198.51.100.20');
        // Tap-through link to the full observations list.
        $response->assertSee('/admin/user-ip-observations?tableSearch=carol_targeted', false);
    }

    public function test_user_with_no_ip_history_renders_neutral_placeholder(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/users/'.$target->id.'/edit');

        $response->assertOk();
        $response->assertSeeText('No auth observations yet.');
    }
}
