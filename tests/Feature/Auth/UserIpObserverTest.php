<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserIpObservation;
use App\Services\UserIpObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Locks the multi-account-by-IP detection contract.
 *
 *   - Recording an IP on a fresh user creates a row with hit_count=1.
 *   - Recording the same (user, ip) again upserts hit_count and
 *     last_seen_at — no duplicate row, no lost first_seen_at.
 *   - Recording an IP that another user has previously used returns the
 *     count of other distinct user_ids on that IP, AND emits a warning
 *     log entry the operator dashboard / alerting can consume.
 *   - Garbage / null IPs are silently no-op (returns 0).
 */
class UserIpObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_observation_creates_row_with_hit_count_one(): void
    {
        $user = User::factory()->create();

        $other = (new UserIpObserver)->record($user, '203.0.113.10', source: 'login');

        $this->assertSame(0, $other);
        $this->assertDatabaseHas('user_ip_observations', [
            'user_id' => $user->id,
            'ip' => '203.0.113.10',
            'hit_count' => 1,
            'source' => 'login',
        ]);
    }

    public function test_repeat_observation_upserts_hit_count_and_keeps_first_seen(): void
    {
        $user = User::factory()->create();
        $obs = new UserIpObserver;

        $obs->record($user, '203.0.113.10', source: 'register');
        $first = UserIpObservation::query()->where('user_id', $user->id)->where('ip', '203.0.113.10')->firstOrFail();
        $firstSeen = $first->first_seen_at->toIso8601String();

        $obs->record($user, '203.0.113.10', source: 'login');
        $obs->record($user, '203.0.113.10', source: 'login');

        $reloaded = UserIpObservation::query()->where('user_id', $user->id)->where('ip', '203.0.113.10')->firstOrFail();
        $this->assertSame(3, $reloaded->hit_count);
        $this->assertSame($firstSeen, $reloaded->first_seen_at->toIso8601String());
        // last source reflects the most recent context.
        $this->assertSame('login', $reloaded->source);
        // Still exactly one row for this (user, ip) pair.
        $this->assertSame(1, UserIpObservation::query()->where('user_id', $user->id)->where('ip', '203.0.113.10')->count());
    }

    public function test_returns_other_user_count_when_ip_is_shared(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();
        $obs = new UserIpObserver;

        // A and B both use 203.0.113.10 first.
        $obs->record($userA, '203.0.113.10');
        $obs->record($userB, '203.0.113.10');

        Log::spy();

        // Now C signs in from the same IP — should see 2 other users.
        $other = $obs->record($userC, '203.0.113.10');

        $this->assertSame(2, $other);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $msg, array $ctx): bool => $msg === 'shared_ip_multi_account'
                && $ctx['user_id'] === $userC->id
                && $ctx['other_user_count'] === 2)
            ->once();
    }

    public function test_garbage_ip_is_silent_noop(): void
    {
        $user = User::factory()->create();
        $obs = new UserIpObserver;

        $this->assertSame(0, $obs->record($user, null));
        $this->assertSame(0, $obs->record($user, ''));
        $this->assertSame(0, $obs->record($user, 'not-an-ip'));
        $this->assertSame(0, UserIpObservation::query()->count());
    }

    public function test_self_only_ip_is_not_flagged_as_shared(): void
    {
        $user = User::factory()->create();
        $obs = new UserIpObserver;

        Log::spy();

        $obs->record($user, '203.0.113.10');
        $obs->record($user, '203.0.113.10');
        $other = $obs->record($user, '203.0.113.10');

        $this->assertSame(0, $other);
        Log::shouldNotHaveReceived('warning');
    }
}
