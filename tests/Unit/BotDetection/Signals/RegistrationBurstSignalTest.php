<?php

declare(strict_types=1);

namespace Tests\Unit\BotDetection\Signals;

use App\BotDetection\Signals\RegistrationBurstSignal;
use App\Models\User;
use App\Models\UserIpObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks RegistrationBurstSignal scoring contract:
 *
 *   - User with no register observation scores 0 silently
 *   - Solo-registered IP (no concurrent peers) scores 0
 *   - 2+ concurrent registrations from same IP within window → score
 *   - Allowlisted IP is skipped entirely (uses shared_ip allowlist)
 *   - Out-of-window peers DON'T contribute (this is what differentiates
 *     burst signal from the broader SharedIpSignal)
 */
class RegistrationBurstSignalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('satpeek.bot_score.registration_burst', [
            'window_hours' => 24,
            'min_others_for_signal' => 2,
            'score_per_other' => 0.25,
            'max_score' => 1.0,
        ]);
        // Empty allowlist by default; test that overrides shared_ip.
        config()->set('satpeek.bot_score.shared_ip.allowlist', []);
    }

    public function test_user_with_no_register_observation_scores_zero(): void
    {
        $user = User::factory()->create();
        // Login observation, NOT register — must not contribute.
        UserIpObservation::create([
            'user_id' => $user->id, 'ip' => '203.0.113.1',
            'first_seen_at' => Carbon::now(), 'last_seen_at' => Carbon::now(),
            'hit_count' => 1, 'source' => 'login',
        ]);

        $result = (new RegistrationBurstSignal)->evaluate($user);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('no_registration_observation', $result['detail']['reason']);
    }

    public function test_solo_registered_ip_scores_zero(): void
    {
        $user = User::factory()->create();
        $this->observeRegister($user, '203.0.113.10', Carbon::now());

        $result = (new RegistrationBurstSignal)->evaluate($user);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('no_burst', $result['detail']['reason']);
        $this->assertSame(0, $result['detail']['max_others_in_window']);
    }

    public function test_two_concurrent_registrations_in_window_fire_signal(): void
    {
        $user = User::factory()->create();
        $now = Carbon::now();
        $this->observeRegister($user, '203.0.113.20', $now);

        // Two other users registered from same IP within window.
        $other1 = User::factory()->create();
        $other2 = User::factory()->create();
        $this->observeRegister($other1, '203.0.113.20', $now->copy()->subHours(2));
        $this->observeRegister($other2, '203.0.113.20', $now->copy()->subHours(5));

        $result = (new RegistrationBurstSignal)->evaluate($user);

        // 2 others * 0.25 = 0.5
        $this->assertSame(0.5, $result['score']);
        $this->assertSame('registration_burst', $result['detail']['reason']);
        $this->assertSame(2, $result['detail']['worst_ip_others']);
        $this->assertSame('203.0.113.20', $result['detail']['worst_ip']);
    }

    public function test_out_of_window_peers_are_ignored(): void
    {
        $user = User::factory()->create();
        $now = Carbon::now();
        $this->observeRegister($user, '203.0.113.30', $now);

        // Three peers, but ALL outside the 24 h window — must score 0.
        for ($i = 1; $i <= 3; $i++) {
            $other = User::factory()->create();
            $this->observeRegister($other, '203.0.113.30', $now->copy()->subDays(7));
        }

        $result = (new RegistrationBurstSignal)->evaluate($user);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame(0, $result['detail']['max_others_in_window']);
    }

    public function test_score_is_capped_at_max_score(): void
    {
        $user = User::factory()->create();
        $now = Carbon::now();
        $this->observeRegister($user, '203.0.113.40', $now);

        // 10 concurrent registrations — uncapped would be 2.5; max caps at 1.0.
        for ($i = 1; $i <= 10; $i++) {
            $other = User::factory()->create();
            $this->observeRegister($other, '203.0.113.40', $now->copy()->subHours($i));
        }

        $result = (new RegistrationBurstSignal)->evaluate($user);

        $this->assertSame(1.0, $result['score']);
    }

    public function test_allowlisted_ip_is_skipped(): void
    {
        config()->set('satpeek.bot_score.shared_ip.allowlist', ['203.0.113.50']);

        $user = User::factory()->create();
        $now = Carbon::now();
        $this->observeRegister($user, '203.0.113.50', $now);
        for ($i = 1; $i <= 3; $i++) {
            $other = User::factory()->create();
            $this->observeRegister($other, '203.0.113.50', $now->copy()->subHours($i));
        }

        $result = (new RegistrationBurstSignal)->evaluate($user);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame(1, $result['detail']['allowlisted_skipped']);
    }

    private function observeRegister(User $u, string $ip, Carbon $when): void
    {
        UserIpObservation::create([
            'user_id' => $u->id,
            'ip' => $ip,
            'first_seen_at' => $when,
            'last_seen_at' => $when,
            'hit_count' => 1,
            'source' => 'register',
        ]);
    }
}
