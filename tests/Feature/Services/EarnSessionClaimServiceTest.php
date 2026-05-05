<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Captcha\TrajectoryTraceProvider;
use App\Enums\EarnSessionStatus;
use App\Models\BalanceLedger;
use App\Models\CaptchaChallenge;
use App\Models\ShortlinkClick;
use App\Models\User;
use App\Services\EarnSessionClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pins the financial invariant that lives at the heart of every earning
 * surface: the atomic `UPDATE WHERE status = 'pending'` filter inside
 * {@see EarnSessionClaimService::claim()} must guarantee a single credit
 * even when two requests collide.
 *
 * SQLite in-memory tests can't run two real connections in parallel, so
 * we don't try to simulate true OS-level concurrency. Instead we walk the
 * code path that matters: a second claim attempt on a row that's already
 * been flipped out of `pending` must return rejected without writing a
 * second ledger row or bumping the balance again. This is exactly the
 * state a concurrent loser would observe inside the transaction.
 *
 * Why one consolidated test for all three surfaces (PTC, shortlink,
 * article)? They route through the SAME service now (post-v0.10.x
 * extraction). Pinning the contract once on the service prevents drift
 * between the three controllers without three near-identical feature
 * tests; the per-surface flow tests already cover the controller-side
 * shape.
 */
class EarnSessionClaimServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_atomic_claim_credits_exactly_once_when_row_is_pending(): void
    {
        [$user, $click, $challenge] = $this->seedClaimable();

        $service = app(EarnSessionClaimService::class);
        $result = $service->claim(
            session: $click->fresh(),
            user: $user->fresh(),
            providedToken: $click->epoch_token,
            captchaId: $challenge->challenge_id,
            notPendingError: 'click_not_pending',
            minElapsedSeconds: 0,
            reason: BalanceLedger::REASON_SHORTLINK,
            referenceType: ShortlinkClick::class,
            rewardSat: 11,
        );

        $this->assertTrue($result->ok);
        $this->assertSame(11, $result->rewardSat);
        $this->assertSame(11, (int) $user->fresh()->balance_sat);
        $this->assertSame(EarnSessionStatus::Verified, ShortlinkClick::find($click->id)->status);
        $this->assertSame(1, BalanceLedger::query()
            ->where('reference_type', ShortlinkClick::class)
            ->where('reference_id', $click->id)
            ->count());
    }

    public function test_second_claim_on_already_verified_row_does_not_double_credit(): void
    {
        [$user, $click, $challenge] = $this->seedClaimable();

        $service = app(EarnSessionClaimService::class);

        // First claim — wins the race. Captcha is consumed here so the
        // second attempt has to use a freshly seeded challenge to even
        // reach the atomic-UPDATE step we're trying to exercise. Without
        // the second challenge the test would short-circuit on
        // `captcha_required` instead of the not-pending path.
        $first = $service->claim(
            session: $click->fresh(),
            user: $user->fresh(),
            providedToken: $click->epoch_token,
            captchaId: $challenge->challenge_id,
            notPendingError: 'click_not_pending',
            minElapsedSeconds: 0,
            reason: BalanceLedger::REASON_SHORTLINK,
            referenceType: ShortlinkClick::class,
            rewardSat: 17,
        );
        $this->assertTrue($first->ok);
        $balanceAfterFirst = (int) $user->fresh()->balance_sat;
        $this->assertSame(17, $balanceAfterFirst);

        // Second claim — would-be loser of a real concurrent race. The
        // row is no longer pending; the atomic UPDATE filters it out;
        // the service returns rejected without entering the credit
        // branch at all.
        $secondChallenge = $this->seedChallenge($user);
        $secondChallenge->update(['status' => 'verified']);
        $second = $service->claim(
            session: $click->fresh(),
            user: $user->fresh(),
            providedToken: $click->epoch_token,
            captchaId: $secondChallenge->challenge_id,
            notPendingError: 'click_not_pending',
            minElapsedSeconds: 0,
            reason: BalanceLedger::REASON_SHORTLINK,
            referenceType: ShortlinkClick::class,
            rewardSat: 17,
        );

        $this->assertFalse($second->ok);
        $this->assertSame('click_not_pending', $second->errorCode);
        $this->assertSame(0, $second->rewardSat);

        // Balance untouched. Single ledger row. Single verified row.
        $this->assertSame($balanceAfterFirst, (int) $user->fresh()->balance_sat);
        $this->assertSame(1, BalanceLedger::query()
            ->where('reference_type', ShortlinkClick::class)
            ->where('reference_id', $click->id)
            ->where('delta_sat', 17)
            ->count(), 'exactly one credit row per session — no double-credit');
    }

    public function test_token_mismatch_returns_token_mismatch_error(): void
    {
        [$user, $click, $challenge] = $this->seedClaimable();

        $service = app(EarnSessionClaimService::class);
        $result = $service->claim(
            session: $click->fresh(),
            user: $user->fresh(),
            providedToken: 'sc_wrong_token',
            captchaId: $challenge->challenge_id,
            notPendingError: 'click_not_pending',
            minElapsedSeconds: 0,
            reason: BalanceLedger::REASON_SHORTLINK,
            referenceType: ShortlinkClick::class,
            rewardSat: 7,
        );

        $this->assertFalse($result->ok);
        $this->assertSame('token_mismatch', $result->errorCode);
        $this->assertSame(0, (int) $user->fresh()->balance_sat);
    }

    public function test_missing_captcha_returns_captcha_required_error(): void
    {
        [$user, $click, $challenge] = $this->seedClaimable();
        $challenge->update(['status' => 'rejected']);

        $service = app(EarnSessionClaimService::class);
        $result = $service->claim(
            session: $click->fresh(),
            user: $user->fresh(),
            providedToken: $click->epoch_token,
            captchaId: $challenge->challenge_id,
            notPendingError: 'click_not_pending',
            minElapsedSeconds: 0,
            reason: BalanceLedger::REASON_SHORTLINK,
            referenceType: ShortlinkClick::class,
            rewardSat: 7,
        );

        $this->assertFalse($result->ok);
        $this->assertSame('captcha_required', $result->errorCode);
        $this->assertSame(0, (int) $user->fresh()->balance_sat);
    }

    public function test_too_fast_rejection_marks_row_and_returns_too_fast(): void
    {
        [$user, $click, $challenge] = $this->seedClaimable();

        $service = app(EarnSessionClaimService::class);
        $result = $service->claim(
            session: $click->fresh(),
            user: $user->fresh(),
            providedToken: $click->epoch_token,
            captchaId: $challenge->challenge_id,
            notPendingError: 'click_not_pending',
            minElapsedSeconds: 9999, // forces too_fast
            reason: BalanceLedger::REASON_SHORTLINK,
            referenceType: ShortlinkClick::class,
            rewardSat: 7,
        );

        $this->assertFalse($result->ok);
        $this->assertSame('too_fast', $result->errorCode);
        $row = ShortlinkClick::find($click->id);
        $this->assertSame(EarnSessionStatus::Rejected, $row->status);
        $this->assertSame('too_fast', $row->rejection_reason);
    }

    public function test_pre_claim_hook_can_reject_with_custom_reason(): void
    {
        [$user, $click, $challenge] = $this->seedClaimable();

        $service = app(EarnSessionClaimService::class);
        $result = $service->claim(
            session: $click->fresh(),
            user: $user->fresh(),
            providedToken: $click->epoch_token,
            captchaId: $challenge->challenge_id,
            notPendingError: 'click_not_pending',
            minElapsedSeconds: 0,
            reason: BalanceLedger::REASON_SHORTLINK,
            referenceType: ShortlinkClick::class,
            rewardSat: 7,
            preClaim: fn (): string => 'heartbeat_deficit',
        );

        $this->assertFalse($result->ok);
        $this->assertSame('heartbeat_deficit', $result->errorCode);
        $row = ShortlinkClick::find($click->id);
        $this->assertSame(EarnSessionStatus::Rejected, $row->status);
        $this->assertSame('heartbeat_deficit', $row->rejection_reason);
        $this->assertSame(0, (int) $user->fresh()->balance_sat);
    }

    public function test_post_credit_hook_runs_inside_credit_transaction(): void
    {
        [$user, $click, $challenge] = $this->seedClaimable();

        $hookFired = false;
        $service = app(EarnSessionClaimService::class);
        $result = $service->claim(
            session: $click->fresh(),
            user: $user->fresh(),
            providedToken: $click->epoch_token,
            captchaId: $challenge->challenge_id,
            notPendingError: 'click_not_pending',
            minElapsedSeconds: 0,
            reason: BalanceLedger::REASON_SHORTLINK,
            referenceType: ShortlinkClick::class,
            rewardSat: 7,
            postCredit: function () use (&$hookFired): void {
                $hookFired = true;
            },
        );

        $this->assertTrue($result->ok);
        $this->assertTrue($hookFired, 'postCredit hook must run on the credit path');
    }

    /**
     * @return array{0: User, 1: ShortlinkClick, 2: CaptchaChallenge}
     */
    private function seedClaimable(): array
    {
        $user = User::factory()->create(['balance_sat' => 0]);
        $click = ShortlinkClick::create([
            'user_id' => $user->id,
            'provider_name' => 'mock',
            'reward_sat' => 7,
            'hold_seconds' => 5,
            'epoch_token' => 'sc_'.str_repeat('a', 28),
            'status' => 'pending',
            'started_at' => Carbon::now()->subSeconds(10),
        ]);
        $challenge = $this->seedChallenge($user);
        $challenge->update(['status' => 'verified']);

        return [$user, $click, $challenge];
    }

    private function seedChallenge(User $user): CaptchaChallenge
    {
        $shape = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 40, 2, 8000, 60);

        return CaptchaChallenge::create([
            'challenge_id' => 'cc_'.uniqid('', true),
            'user_id' => $user->id,
            'session_id' => 'test',
            'provider' => 'trajectory_trace',
            'seed' => 'test-seed',
            'expected_shape' => $shape,
            'fingerprint_hash' => null,
            'client_ip' => '127.0.0.1',
            'ja4' => null,
            'user_agent' => 'phpunit',
            'status' => 'issued',
            'issued_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);
    }
}
