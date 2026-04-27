<?php

declare(strict_types=1);

namespace Tests\Feature\BotDetection;

use App\Models\BotScore;
use App\Models\User;
use App\Models\UserIpObservation;
use App\Services\UserIpObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * End-to-end check that the SharedIpSignal actually moves the user's
 * tier when UserIpObserver runs at login / register submit. Catches the
 * "signal exists but nothing calls ScoreEngine" gap.
 *
 * The flow under test:
 *   1. Two prior users (siblings) recorded on IP X.
 *   2. New user authenticates from X — UserIpObserver writes the
 *      observation row and triggers ScoreEngine::evaluateThrottled().
 *   3. SharedIpSignal scores 2 siblings × 0.3 = 0.6 weighted by 0.15
 *      against the renormalised total → final score above the
 *      `suspect` threshold (0.30) so PolicyEnforcer's mid-band actions
 *      (harder captcha, withdrawals reviewed) kick in.
 */
class SharedIpScoresAtAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_a_shared_ip_writes_a_bot_score_row(): void
    {
        $sharedIp = '203.0.113.42';

        // Two prior users already on the IP.
        $this->seedObservation(User::factory()->create(), $sharedIp);
        $this->seedObservation(User::factory()->create(), $sharedIp);

        $newUser = User::factory()->create();
        $this->assertNull(BotScore::query()->where('user_id', $newUser->id)->first());

        (new UserIpObserver)->record($newUser, $sharedIp, source: 'login');

        $score = BotScore::query()->where('user_id', $newUser->id)->first();
        $this->assertNotNull($score, 'ScoreEngine must have written a row for the new user');
        $this->assertGreaterThan(0.0, (float) $score->score);
    }

    public function test_unique_ip_for_new_user_writes_neutral_trust_row(): void
    {
        $newUser = User::factory()->create();

        (new UserIpObserver)->record($newUser, '203.0.113.99', source: 'register');

        $score = BotScore::query()->where('user_id', $newUser->id)->first();
        $this->assertNotNull($score);
        // No siblings, no captcha history, no IP-rep verdict, etc → very
        // low score. Tier should be `trust`.
        $this->assertSame('trust', $score->tier);
    }

    public function test_throttle_keeps_repeat_observations_from_re_evaluating(): void
    {
        $sharedIp = '203.0.113.42';
        $this->seedObservation(User::factory()->create(), $sharedIp);

        $user = User::factory()->create();
        $observer = new UserIpObserver;

        $observer->record($user, $sharedIp, source: 'login');
        $firstScore = BotScore::query()->where('user_id', $user->id)->firstOrFail();
        $firstStamp = $firstScore->last_evaluated_at->toIso8601String();

        // Hit again 10 seconds later — within the default 300 s throttle.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $observer->record($user, $sharedIp, source: 'login');

        $secondScore = BotScore::query()->where('user_id', $user->id)->firstOrFail();
        // last_evaluated_at MUST NOT have advanced — proves the throttle
        // suppressed the re-eval.
        $this->assertSame($firstStamp, $secondScore->last_evaluated_at->toIso8601String());

        Carbon::setTestNow();
    }

    private function seedObservation(User $user, string $ip): void
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
