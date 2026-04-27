<?php

declare(strict_types=1);

namespace Tests\Unit\BotDetection\Signals;

use App\BotDetection\Signals\SharedIpSignal;
use App\Models\User;
use App\Models\UserIpObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks the multi-account-by-IP scoring contract:
 *
 *   - A user with no IP observations scores 0.0 silently.
 *   - A user whose IPs have never been used by anyone else scores 0.0
 *     (the unique home / mobile case — must not false-positive).
 *   - The score grows linearly with the cross-account count on the
 *     WORST IP in the user's history (capped to max_score) — the
 *     intuition is "even one shared IP is suspicious", but a clean
 *     home IP can't redeem a sock-puppet IP.
 *   - Operator config (`bot_score.shared_ip.*`) drives the threshold
 *     so a shared-NAT setting can be relaxed without code changes.
 */
class SharedIpSignalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('satpeek.bot_score.shared_ip', [
            'min_others_for_signal' => 1,
            'score_per_other' => 0.3,
            'max_score' => 1.0,
        ]);
    }

    public function test_user_with_no_observations_scores_zero(): void
    {
        $user = User::factory()->create();

        $result = (new SharedIpSignal)->evaluate($user);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('no_observations', $result['detail']['reason']);
    }

    public function test_unique_ip_history_scores_zero(): void
    {
        $user = User::factory()->create();
        $this->observe($user, '203.0.113.10');
        $this->observe($user, '203.0.113.11');

        $result = (new SharedIpSignal)->evaluate($user);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('no_shared_ip', $result['detail']['reason']);
        $this->assertSame(2, $result['detail']['ip_count']);
        $this->assertSame(0, $result['detail']['max_others']);
    }

    public function test_one_shared_ip_with_one_other_user_scores_score_per_other(): void
    {
        $user = User::factory()->create();
        $sibling = User::factory()->create();
        $this->observe($user, '203.0.113.10');
        $this->observe($sibling, '203.0.113.10');

        $result = (new SharedIpSignal)->evaluate($user);

        // 1 other user × 0.3 score_per_other = 0.3.
        $this->assertEqualsWithDelta(0.3, $result['score'], 0.001);
        $this->assertSame('shared_ip', $result['detail']['reason']);
        $this->assertSame(1, $result['detail']['worst_ip_others']);
        $this->assertSame('203.0.113.10', $result['detail']['worst_ip']);
    }

    public function test_score_caps_at_max_score(): void
    {
        $user = User::factory()->create();
        $this->observe($user, '203.0.113.10');
        // 5 sibling accounts on the same IP — raw score 1.5, capped to 1.0.
        for ($i = 0; $i < 5; $i++) {
            $this->observe(User::factory()->create(), '203.0.113.10');
        }

        $result = (new SharedIpSignal)->evaluate($user);

        $this->assertSame(1.0, $result['score']);
        $this->assertSame(5, $result['detail']['worst_ip_others']);
    }

    public function test_uses_worst_ip_not_average_when_user_has_mixed_history(): void
    {
        // User has TWO IPs: a clean home IP (no others), and a shared
        // sock-puppet IP. Score must reflect the worst, not be diluted
        // by averaging in the clean one.
        $user = User::factory()->create();
        $this->observe($user, '203.0.113.10'); // clean
        $this->observe($user, '198.51.100.20'); // shared
        $this->observe(User::factory()->create(), '198.51.100.20');
        $this->observe(User::factory()->create(), '198.51.100.20');

        $result = (new SharedIpSignal)->evaluate($user);

        // 2 siblings on the worst IP × 0.3 = 0.6.
        $this->assertEqualsWithDelta(0.6, $result['score'], 0.001);
        $this->assertSame('198.51.100.20', $result['detail']['worst_ip']);
    }

    public function test_min_others_threshold_suppresses_signal_when_under(): void
    {
        // Operator relaxed the policy — a shared NAT environment.
        // 1 sibling is now treated as "no signal".
        config()->set('satpeek.bot_score.shared_ip.min_others_for_signal', 2);

        $user = User::factory()->create();
        $this->observe($user, '203.0.113.10');
        $this->observe(User::factory()->create(), '203.0.113.10');

        $result = (new SharedIpSignal)->evaluate($user);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('no_shared_ip', $result['detail']['reason']);
    }

    public function test_distinct_user_count_not_row_count(): void
    {
        // Sibling logged in 5 times from the same IP. Should still
        // count as ONE other user, not five — composite UNIQUE on
        // (user_id, ip) means upsert, but the test guards against a
        // future schema change that drops the unique.
        $user = User::factory()->create();
        $sibling = User::factory()->create();
        $this->observe($user, '203.0.113.10');
        $this->observe($sibling, '203.0.113.10');
        UserIpObservation::query()
            ->where('user_id', $sibling->id)
            ->where('ip', '203.0.113.10')
            ->update(['hit_count' => 5]);

        $result = (new SharedIpSignal)->evaluate($user);

        $this->assertSame(1, $result['detail']['worst_ip_others']);
    }

    private function observe(User $user, string $ip): void
    {
        UserIpObservation::create([
            'user_id' => $user->id,
            'ip' => $ip,
            'first_seen_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
            'hit_count' => 1,
            'source' => 'login',
        ]);
    }
}
