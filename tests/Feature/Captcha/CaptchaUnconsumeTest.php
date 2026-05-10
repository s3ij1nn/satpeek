<?php

declare(strict_types=1);

namespace Tests\Feature\Captcha;

use App\Captcha\CaptchaConsumer;
use App\Models\CaptchaChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pins the unconsume() compensating action shipped after the
 * silent-failure-hunter MEDIUM finding.
 *
 * Background: CaptchaConsumer::consume() flips a verified row to
 * `consumed` in its own committed transaction BEFORE the caller's
 * outer credit transaction runs. If the credit transaction fails
 * (DB error, gateway exception, etc.), the captcha row was already
 * `consumed` and the user can never retry — they lose their solved
 * captcha with nothing credited.
 *
 * EarnSessionClaimService now wraps the credit transaction in a
 * try/catch + atomic-loss check that calls unconsume() in the
 * failure branch. These tests pin the unconsume primitive directly.
 */
class CaptchaUnconsumeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconsume_reverts_consumed_row_back_to_verified(): void
    {
        $user = User::factory()->create();
        $challenge = $this->seedChallenge($user, 'consumed');

        $reverted = CaptchaConsumer::unconsume($challenge->challenge_id, $user);

        $this->assertTrue($reverted);
        $this->assertSame('verified', CaptchaChallenge::find($challenge->id)->status);
    }

    public function test_unconsume_is_noop_for_already_verified_row(): void
    {
        // Defensive: if a parallel path raced and already reverted the
        // captcha (or the row was never consumed), unconsume must NOT
        // mutate it — only flips `consumed → verified`, never any other
        // transition.
        $user = User::factory()->create();
        $challenge = $this->seedChallenge($user, 'verified');

        $reverted = CaptchaConsumer::unconsume($challenge->challenge_id, $user);

        $this->assertFalse($reverted);
        $this->assertSame('verified', CaptchaChallenge::find($challenge->id)->status);
    }

    public function test_unconsume_refuses_cross_user_revert(): void
    {
        // Defence-in-depth: unconsume is user-scoped. Attacker who
        // triggered a credit failure on their own session must not be
        // able to revert another user's consumed challenge.
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $challenge = $this->seedChallenge($owner, 'consumed');

        $reverted = CaptchaConsumer::unconsume($challenge->challenge_id, $attacker);

        $this->assertFalse($reverted);
        $this->assertSame('consumed', CaptchaChallenge::find($challenge->id)->status);
    }

    public function test_unconsume_returns_false_for_unknown_challenge_id(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(CaptchaConsumer::unconsume('cc_nonexistent', $user));
        $this->assertFalse(CaptchaConsumer::unconsume('', $user));
        $this->assertFalse(CaptchaConsumer::unconsume(null, $user));
        $this->assertFalse(CaptchaConsumer::unconsume('cc_nonexistent', null));
    }

    private function seedChallenge(User $user, string $status): CaptchaChallenge
    {
        return CaptchaChallenge::create([
            'challenge_id' => 'cc_'.uniqid('', true),
            'user_id' => $user->id,
            'session_id' => 'test',
            'provider' => 'trajectory_trace',
            'seed' => 'test-seed',
            'expected_shape' => [['x' => 0, 'y' => 0, 't' => 0]],
            'fingerprint_hash' => null,
            'client_ip' => '127.0.0.1',
            'ja4' => null,
            'user_agent' => 'phpunit',
            'status' => $status,
            'issued_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);
    }
}
