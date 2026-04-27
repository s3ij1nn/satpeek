<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Models\PtcView;
use App\Models\Referral;
use App\Models\User;
use App\Services\ReferralPayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the affiliate funding contract:
 *
 *   - Commission is funded out of the platform's ad-commission pool —
 *     `min(referral_pct, ads.commission_pct)` so the affiliate program
 *     never exceeds the platform's collected commission for paid-ad
 *     surfaces, and stays a defensive cap on every other surface.
 *   - Returns 0 (no ledger row written) when the user has no referrer,
 *     reward is non-positive, or the percentage rounds to zero.
 *   - Lifetime commission counter on the Referral row tracks across
 *     repeat invocations.
 */
class ReferralPayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('satpeek.referral.commission_pct', 10);
        config()->set('satpeek.ads.commission_pct', 25);
    }

    public function test_pays_referrer_at_referral_pct_when_under_platform_cap(): void
    {
        // 100 sat reward, 10 % referral, 25 % platform pool — pays 10 sat.
        [$referrer, $referee] = $this->refereePair();

        $paid = (new ReferralPayout)->settle($referee, 100, PtcView::class, 1);

        $this->assertSame(10, $paid);
        $this->assertSame(10, (int) $referrer->fresh()->balance_sat);
        $this->assertDatabaseHas('balance_ledgers', [
            'user_id' => $referrer->id,
            'reason' => 'referral_commission',
            'delta_sat' => 10,
        ]);
    }

    public function test_caps_at_platform_commission_pool_when_referral_pct_exceeds_it(): void
    {
        // Misconfigured — referral 50 % > platform 25 %. Cap to 25 % so the
        // operator never bleeds money on referrals beyond the commission
        // they actually collected.
        config()->set('satpeek.referral.commission_pct', 50);
        [$referrer, $referee] = $this->refereePair();

        $paid = (new ReferralPayout)->settle($referee, 100, PtcView::class, 1);

        $this->assertSame(25, $paid);
        $this->assertSame(25, (int) $referrer->fresh()->balance_sat);
    }

    public function test_no_referrer_means_no_payout(): void
    {
        $loner = User::factory()->create(['referrer_id' => null]);

        $paid = (new ReferralPayout)->settle($loner, 100, PtcView::class, 1);

        $this->assertSame(0, $paid);
        $this->assertDatabaseMissing('balance_ledgers', ['reason' => 'referral_commission']);
    }

    public function test_zero_reward_short_circuits(): void
    {
        [, $referee] = $this->refereePair();

        $this->assertSame(0, (new ReferralPayout)->settle($referee, 0, PtcView::class, 1));
        $this->assertSame(0, (new ReferralPayout)->settle($referee, -50, PtcView::class, 1));
    }

    public function test_pct_below_one_rounds_to_zero_and_skips_ledger_row(): void
    {
        // 1 sat reward × 10 % = 0 (floor). Don't write a noise row.
        [, $referee] = $this->refereePair();

        $paid = (new ReferralPayout)->settle($referee, 1, PtcView::class, 1);

        $this->assertSame(0, $paid);
        $this->assertDatabaseMissing('balance_ledgers', ['reason' => 'referral_commission']);
    }

    public function test_lifetime_commission_accumulates_on_referral_row(): void
    {
        [$referrer, $referee] = $this->refereePair();
        $payout = new ReferralPayout;

        $payout->settle($referee, 100, PtcView::class, 1);
        $payout->settle($referee, 50, PtcView::class, 2);
        $payout->settle($referee, 200, PtcView::class, 3);

        $row = Referral::query()
            ->where('referrer_id', $referrer->id)
            ->where('referred_id', $referee->id)
            ->firstOrFail();
        // 10 + 5 + 20 = 35 sat lifetime.
        $this->assertSame(35, (int) $row->lifetime_commission_sat);
    }

    /** @return array{0: User, 1: User} */
    private function refereePair(): array
    {
        $referrer = User::factory()->create();
        $referee = User::factory()->create(['referrer_id' => $referrer->id]);
        Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $referee->id]);

        return [$referrer, $referee];
    }
}
