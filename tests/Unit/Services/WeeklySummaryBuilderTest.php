<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\BotScoreHistory;
use App\Models\InternalArticle;
use App\Models\InternalArticleView;
use App\Models\PtcAd;
use App\Models\PtcView;
use App\Models\ShortlinkClick;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\WeeklySummaryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks the WeeklySummaryBuilder bucket shape + window semantics.
 *
 * Three properties pinned:
 *   1. Trailing 7-day window vs prior 7-day window
 *      (delta = this - previous)
 *   2. Verified-only counting on earning surfaces
 *   3. Out-of-window rows don't bleed into either bucket
 */
class WeeklySummaryBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_state_returns_zero_filled_buckets(): void
    {
        $payload = (new WeeklySummaryBuilder)->build();

        $this->assertSame(0, $payload['earning']['ptc_views']['this']);
        $this->assertSame(0, $payload['earning']['shortlink_clicks']['this']);
        $this->assertSame(0, $payload['earning']['article_reads']['this']);
        $this->assertSame(0, $payload['payouts']['sent_count']);
        $this->assertSame(0, $payload['payouts']['sent_total_sat']);
        $this->assertSame(0, $payload['users']['new_this_week']);
        $this->assertSame(0, $payload['tier_transitions']['banned']);
    }

    public function test_verified_counts_in_window_only(): void
    {
        $now = Carbon::parse('2026-05-04 12:00:00');
        Carbon::setTestNow($now);

        $u = User::factory()->create();
        $ad = PtcAd::create([
            'source' => 'mock', 'external_id' => 'ad-'.uniqid(),
            'title' => 'x', 'target_url' => 'https://e.x', 'reward_sat' => 1,
            'duration_sec' => 5, 'daily_limit_per_user' => 5,
            'is_active' => true, 'status' => 'approved',
        ]);

        // 3 verified PTC views in the last 7 days.
        for ($i = 0; $i < 3; $i++) {
            $v = PtcView::create([
                'user_id' => $u->id, 'ptc_ad_id' => $ad->id,
                'epoch_token' => 'pv_'.uniqid().'_'.$i,
                'status' => 'verified',
                'started_at' => $now->copy()->subDays(2),
                'completed_at' => $now->copy()->subDays(2)->addSeconds(5),
                'heartbeats_received' => 3, 'heartbeats_expected' => 3,
            ]);
            $v->forceFill([
                'created_at' => $now->copy()->subDays(2),
                'updated_at' => $now->copy()->subDays(2),
            ])->save();
        }

        // 1 PREVIOUS-WEEK verified view (10 days ago).
        $prevView = PtcView::create([
            'user_id' => $u->id, 'ptc_ad_id' => $ad->id,
            'epoch_token' => 'pv_prev',
            'status' => 'verified',
            'started_at' => $now->copy()->subDays(10),
            'completed_at' => $now->copy()->subDays(10)->addSeconds(5),
            'heartbeats_received' => 3, 'heartbeats_expected' => 3,
        ]);
        $prevView->forceFill([
            'created_at' => $now->copy()->subDays(10),
            'updated_at' => $now->copy()->subDays(10),
        ])->save();

        // 1 in-window REJECTED view — must NOT count.
        PtcView::create([
            'user_id' => $u->id, 'ptc_ad_id' => $ad->id,
            'epoch_token' => 'pv_rej',
            'status' => 'rejected',
            'started_at' => $now->copy()->subDays(1),
            'heartbeats_received' => 0, 'heartbeats_expected' => 3,
        ]);

        $payload = (new WeeklySummaryBuilder)->build($now);
        $this->assertSame(3, $payload['earning']['ptc_views']['this']);
        $this->assertSame(1, $payload['earning']['ptc_views']['previous']);
        $this->assertSame(2, $payload['earning']['ptc_views']['delta']);

        Carbon::setTestNow();
    }

    public function test_payouts_aggregate_sent_total_sat(): void
    {
        $now = Carbon::parse('2026-05-04 12:00:00');
        Carbon::setTestNow($now);

        $u = User::factory()->create();

        $sent1 = Withdrawal::create([
            'user_id' => $u->id, 'amount_sat' => 1500,
            'faucetpay_email' => 'a@x', 'currency' => 'BTC',
            'status' => 'sent',
        ]);
        $sent1->forceFill([
            'created_at' => $now->copy()->subDays(2),
            'updated_at' => $now->copy()->subDays(2),
        ])->save();

        $sent2 = Withdrawal::create([
            'user_id' => $u->id, 'amount_sat' => 4500,
            'faucetpay_email' => 'a@x', 'currency' => 'BTC',
            'status' => 'sent',
        ]);
        $sent2->forceFill([
            'created_at' => $now->copy()->subDays(1),
            'updated_at' => $now->copy()->subDays(1),
        ])->save();

        // Backdate by an hour so the `<` upper-bound filter (which
        // excludes rows EXACTLY at the reference timestamp under
        // setTestNow) doesn't drop these.
        $f = Withdrawal::create([
            'user_id' => $u->id, 'amount_sat' => 9999,
            'faucetpay_email' => 'a@x', 'currency' => 'BTC',
            'status' => 'failed',
        ]);
        $f->forceFill([
            'created_at' => $now->copy()->subHour(),
            'updated_at' => $now->copy()->subHour(),
        ])->save();
        $h = Withdrawal::create([
            'user_id' => $u->id, 'amount_sat' => 7500,
            'faucetpay_email' => 'a@x', 'currency' => 'BTC',
            'status' => 'hold',
        ]);
        $h->forceFill([
            'created_at' => $now->copy()->subHour(),
            'updated_at' => $now->copy()->subHour(),
        ])->save();

        $payload = (new WeeklySummaryBuilder)->build($now);

        $this->assertSame(2, $payload['payouts']['sent_count']);
        $this->assertSame(6000, $payload['payouts']['sent_total_sat']);
        $this->assertSame(1, $payload['payouts']['failed_count']);
        $this->assertSame(1, $payload['payouts']['hold_count']);

        Carbon::setTestNow();
    }

    public function test_tier_transitions_count_each_evaluation_not_unique_users(): void
    {
        $now = Carbon::parse('2026-05-04 12:00:00');
        Carbon::setTestNow($now);

        $u = User::factory()->create();

        // 2 suspect + 1 banned this week.
        BotScoreHistory::create(['user_id' => $u->id, 'score' => 0.45, 'tier' => 'suspect', 'signals' => [], 'created_at' => $now->copy()->subDays(3)]);
        BotScoreHistory::create(['user_id' => $u->id, 'score' => 0.45, 'tier' => 'suspect', 'signals' => [], 'created_at' => $now->copy()->subDays(2)]);
        BotScoreHistory::create(['user_id' => $u->id, 'score' => 0.95, 'tier' => 'banned', 'signals' => [], 'created_at' => $now->copy()->subDays(1)]);

        // Trust evaluations are NOT counted (those would dwarf the
        // signal of interest — operators only care about non-trust
        // velocity per the docblock).
        BotScoreHistory::create(['user_id' => $u->id, 'score' => 0.10, 'tier' => 'trust', 'signals' => [], 'created_at' => $now->copy()->subDays(2)]);

        // Out-of-window banned — must not count.
        $old = BotScoreHistory::create(['user_id' => $u->id, 'score' => 0.99, 'tier' => 'banned', 'signals' => [], 'created_at' => $now->copy()->subDays(20)]);
        $old->forceFill(['created_at' => $now->copy()->subDays(20)])->save();

        $payload = (new WeeklySummaryBuilder)->build($now);

        $this->assertSame(2, $payload['tier_transitions']['suspect']);
        $this->assertSame(0, $payload['tier_transitions']['likely_bot']);
        $this->assertSame(1, $payload['tier_transitions']['banned']);

        Carbon::setTestNow();
    }
}
