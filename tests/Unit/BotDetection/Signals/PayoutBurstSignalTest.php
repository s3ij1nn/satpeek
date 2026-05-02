<?php

declare(strict_types=1);

namespace Tests\Unit\BotDetection\Signals;

use App\BotDetection\Signals\PayoutBurstSignal;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks PayoutBurstSignal scoring contract:
 *
 *   - 0 / 1 / 2 withdrawals in 24 h window scores 0 (under threshold)
 *   - 3 hits the floor and starts scoring; each additional adds 0.2
 *   - Out-of-window withdrawals are ignored
 *   - Status doesn't matter — pending/queued/failed/hold all count
 *     because a flurry of failed attempts is also signal
 *   - Cap at max_score
 */
class PayoutBurstSignalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('satpeek.bot_score.payout_burst', [
            'window_hours' => 24,
            'min_for_signal' => 3,
            'score_per_extra' => 0.2,
            'max_score' => 1.0,
        ]);
    }

    public function test_no_withdrawals_scores_zero(): void
    {
        $user = User::factory()->create();

        $result = (new PayoutBurstSignal)->evaluate($user);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('no_burst', $result['detail']['reason']);
        $this->assertSame(0, $result['detail']['count_in_window']);
    }

    public function test_two_withdrawals_under_threshold_scores_zero(): void
    {
        $user = User::factory()->create();
        $this->seedWithdrawal($user);
        $this->seedWithdrawal($user);

        $result = (new PayoutBurstSignal)->evaluate($user);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame(2, $result['detail']['count_in_window']);
    }

    public function test_three_withdrawals_hits_threshold_and_scores(): void
    {
        $user = User::factory()->create();
        $this->seedWithdrawal($user);
        $this->seedWithdrawal($user);
        $this->seedWithdrawal($user);

        $result = (new PayoutBurstSignal)->evaluate($user);

        // extra = 3 - 3 + 1 = 1 → 1 * 0.2 = 0.2
        $this->assertSame(0.2, $result['score']);
        $this->assertSame('payout_burst', $result['detail']['reason']);
        $this->assertSame(3, $result['detail']['count_in_window']);
    }

    public function test_score_grows_linearly_above_threshold(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->seedWithdrawal($user);
        }

        $result = (new PayoutBurstSignal)->evaluate($user);

        // extra = 5 - 3 + 1 = 3 → 3 * 0.2 = 0.6 (float-arithmetic delta)
        $this->assertEqualsWithDelta(0.6, $result['score'], 1e-9);
        $this->assertSame(5, $result['detail']['count_in_window']);
    }

    public function test_out_of_window_withdrawals_are_ignored(): void
    {
        $user = User::factory()->create();
        // Two recent — under threshold by themselves.
        $this->seedWithdrawal($user);
        $this->seedWithdrawal($user);
        // Three more from a week ago — must NOT count.
        for ($i = 0; $i < 3; $i++) {
            $w = $this->seedWithdrawal($user);
            $w->forceFill([
                'created_at' => Carbon::now()->subDays(7),
                'updated_at' => Carbon::now()->subDays(7),
            ])->save();
        }

        $result = (new PayoutBurstSignal)->evaluate($user);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame(2, $result['detail']['count_in_window']);
    }

    public function test_failed_status_still_counts_toward_burst(): void
    {
        // The bot pattern of "spam withdraw attempts hoping one slips through"
        // shows up as multiple `failed` rows. Must not be filtered out.
        $user = User::factory()->create();
        $this->seedWithdrawal($user, 'failed');
        $this->seedWithdrawal($user, 'failed');
        $this->seedWithdrawal($user, 'hold');

        $result = (new PayoutBurstSignal)->evaluate($user);

        $this->assertSame(0.2, $result['score']);
    }

    public function test_score_is_capped_at_max(): void
    {
        $user = User::factory()->create();
        // 10 withdrawals → uncapped score = 8 * 0.2 = 1.6 → capped at 1.0
        for ($i = 0; $i < 10; $i++) {
            $this->seedWithdrawal($user);
        }

        $result = (new PayoutBurstSignal)->evaluate($user);

        $this->assertSame(1.0, $result['score']);
    }

    private function seedWithdrawal(User $u, string $status = 'queued'): Withdrawal
    {
        return Withdrawal::create([
            'user_id' => $u->id,
            'amount_sat' => 1000,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
            'status' => $status,
        ]);
    }
}
