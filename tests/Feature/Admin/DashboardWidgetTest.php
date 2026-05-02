<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Widgets\BotTierDistributionWidget;
use App\Filament\Widgets\BotTierTrendChartWidget;
use App\Filament\Widgets\EarningActivityWidget;
use App\Filament\Widgets\InFlightWithdrawalsWidget;
use App\Filament\Widgets\PayoutVolumeChartWidget;
use App\Filament\Widgets\SharedIpDetectionsWidget;
use App\Models\BalanceLedger;
use App\Models\BotScore;
use App\Models\BotScoreHistory;
use App\Models\InternalArticle;
use App\Models\InternalArticleView;
use App\Models\PtcAd;
use App\Models\PtcView;
use App\Models\ShortlinkClick;
use App\Models\User;
use App\Models\UserIpObservation;
use App\Models\Withdrawal;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Locks the dashboard widget data contract. Widgets are admin-only by
 * inheritance from `User::canAccessPanel()` (Filament panel guard); the
 * rendering happens via Livewire and is exercised separately. These tests
 * pin the aggregate-query layer so a future schema change (rename `tier`,
 * add a status, etc.) surfaces immediately.
 */
class DashboardWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_flight_widget_zero_state_renders_drained_message(): void
    {
        $stats = $this->extractStats(new InFlightWithdrawalsWidget);

        $this->assertCount(3, $stats);
        // First card = "In flight". Empty queue → success colour, "0".
        [$inFlight, $hold, $failed] = $stats;
        $this->assertSame('In flight', $inFlight->getLabel());
        $this->assertSame('0', $inFlight->getValue());
        $this->assertSame('queue is drained', $inFlight->getDescription());

        $this->assertSame('Hold (review)', $hold->getLabel());
        $this->assertSame('0', $hold->getValue());

        $this->assertSame('Failed (24 h)', $failed->getLabel());
        $this->assertSame('0', $failed->getValue());
    }

    public function test_in_flight_widget_aggregates_amount_sat_across_queued_and_processing(): void
    {
        $u = User::factory()->create();
        Withdrawal::create([
            'user_id' => $u->id, 'amount_sat' => 1500, 'faucetpay_email' => 'a@x', 'currency' => 'BTC',
            'status' => 'queued',
        ]);
        Withdrawal::create([
            'user_id' => $u->id, 'amount_sat' => 2000, 'faucetpay_email' => 'a@x', 'currency' => 'BTC',
            'status' => 'processing',
        ]);
        // 'hold' MUST NOT be counted in In flight — it's the manual-review
        // bucket which has different operator semantics.
        Withdrawal::create([
            'user_id' => $u->id, 'amount_sat' => 9999, 'faucetpay_email' => 'a@x', 'currency' => 'BTC',
            'status' => 'hold',
        ]);
        // 'sent' / 'failed' MUST NOT be counted either.
        Withdrawal::create([
            'user_id' => $u->id, 'amount_sat' => 5000, 'faucetpay_email' => 'a@x', 'currency' => 'BTC',
            'status' => 'sent',
        ]);

        [$inFlight, $hold] = $this->extractStats(new InFlightWithdrawalsWidget);

        $this->assertStringContainsString('2', (string) $inFlight->getValue());
        $this->assertStringContainsString('3,500', (string) $inFlight->getValue());
        $this->assertSame('1', $hold->getValue());
    }

    public function test_in_flight_widget_failed_24h_excludes_older_rows(): void
    {
        $u = User::factory()->create();
        // Within window.
        Withdrawal::create([
            'user_id' => $u->id, 'amount_sat' => 100, 'faucetpay_email' => 'a@x', 'currency' => 'BTC',
            'status' => 'failed',
        ]);
        // 2 days old — must be excluded. Eloquent's mass update would touch
        // updated_at and refuse to overwrite created_at, so write through
        // the query builder.
        $old = Withdrawal::create([
            'user_id' => $u->id, 'amount_sat' => 100, 'faucetpay_email' => 'a@x', 'currency' => 'BTC',
            'status' => 'failed',
        ]);
        Withdrawal::query()->where('id', $old->id)->update(['created_at' => now()->subDays(2)]);

        [, , $failed] = $this->extractStats(new InFlightWithdrawalsWidget);

        $this->assertSame('1', $failed->getValue());
    }

    public function test_bot_tier_widget_zero_state_returns_four_zero_cards(): void
    {
        $stats = $this->extractStats(new BotTierDistributionWidget);

        $this->assertCount(4, $stats);
        foreach ($stats as $stat) {
            $this->assertSame('0', $stat->getValue());
        }
        $this->assertSame(['Trust', 'Suspect', 'Likely bot', 'Banned'], array_map(fn (Stat $s) => $s->getLabel(), $stats));
    }

    public function test_bot_tier_widget_groups_correctly_across_tiers(): void
    {
        // Three trust + two suspect + one banned. likely_bot stays at zero.
        foreach (range(1, 3) as $_) {
            BotScore::create(['user_id' => User::factory()->create()->id, 'score' => 0.10, 'tier' => 'trust']);
        }
        foreach (range(1, 2) as $_) {
            BotScore::create(['user_id' => User::factory()->create()->id, 'score' => 0.45, 'tier' => 'suspect']);
        }
        BotScore::create(['user_id' => User::factory()->create()->id, 'score' => 0.95, 'tier' => 'banned']);

        [$trust, $suspect, $likelyBot, $banned] = $this->extractStats(new BotTierDistributionWidget);

        $this->assertSame('3', $trust->getValue());
        $this->assertSame('2', $suspect->getValue());
        $this->assertSame('0', $likelyBot->getValue());
        $this->assertSame('1', $banned->getValue());
    }

    public function test_shared_ip_widget_zero_state(): void
    {
        $stats = $this->extractStats(new SharedIpDetectionsWidget);

        $this->assertCount(3, $stats);
        foreach ($stats as $stat) {
            $this->assertSame('0', $stat->getValue());
        }
        $labels = array_map(fn (Stat $s) => $s->getLabel(), $stats);
        $this->assertSame(['Shared-IP hits (24 h)', 'Distinct shared IPs', 'Distinct users on those IPs'], $labels);
    }

    public function test_shared_ip_widget_counts_only_recently_seen_observations_on_shared_ips(): void
    {
        // Two users on the same IP, both observed within the last 24 h.
        $sharedIp = '203.0.113.42';
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $this->observe($u1, $sharedIp, hoursAgo: 1);
        $this->observe($u2, $sharedIp, hoursAgo: 6);
        // Same IP, but the third observation is BEYOND the 24 h window
        // and must not contribute to the recent flagged count (though
        // it does keep the IP in the "shared" subquery).
        $u3 = User::factory()->create();
        $this->observe($u3, $sharedIp, hoursAgo: 48);
        // A different IP with only ONE user — must not contribute at all.
        $this->observe(User::factory()->create(), '198.51.100.99', hoursAgo: 2);

        [$flagged, $distinctIps, $distinctUsers] = $this->extractStats(new SharedIpDetectionsWidget);

        // 2 recent observations on the shared IP.
        $this->assertSame('2', $flagged->getValue());
        // 1 distinct shared IP in the last 24 h.
        $this->assertSame('1', $distinctIps->getValue());
        // 2 distinct users in the last 24 h on shared IPs.
        $this->assertSame('2', $distinctUsers->getValue());
    }

    public function test_widgets_are_admin_only_via_panel_guard(): void
    {
        // Panel-level guard is User::canAccessPanel() — non-admin users can't
        // reach /admin/* at all, so the widgets they would render are
        // unreachable too. Verify the gate still holds for /admin root.
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get('/admin');

        $this->assertContains($response->getStatusCode(), [302, 403, 404]);
    }

    public function test_admin_dashboard_loads_with_widgets_registered(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);

        $response = $this->actingAs($admin)->get('/admin');

        // Filament 3 widgets render via Livewire after first paint, so the
        // initial HTML only carries Livewire placeholders. We assert the
        // route resolves cleanly — the data-layer contract above already
        // pins the actual widget output. The smoke test here catches the
        // case where a widget class fails to autoload or registers wrong.
        $response->assertOk();
    }

    public function test_earning_activity_widget_zero_state(): void
    {
        $stats = $this->extractStats(new EarningActivityWidget);

        $this->assertCount(3, $stats);
        [$ptc, $shortlink, $article] = $stats;
        $this->assertSame('PTC views (24 h)', $ptc->getLabel());
        $this->assertSame('0', $ptc->getValue());
        $this->assertSame('Shortlink clicks (24 h)', $shortlink->getLabel());
        $this->assertSame('Article reads (24 h)', $article->getLabel());
    }

    public function test_earning_activity_widget_counts_only_verified_in_today_window(): void
    {
        $u = User::factory()->create();
        $ad = PtcAd::create([
            'source' => 'mock', 'external_id' => 'ad-'.uniqid(),
            'title' => 'x', 'target_url' => 'https://e.x', 'reward_sat' => 1,
            'duration_sec' => 5, 'daily_limit_per_user' => 5,
            'is_active' => true, 'status' => 'approved',
        ]);
        $article = InternalArticle::create([
            'title' => 'a', 'body' => 'b', 'reward_sat' => 1, 'read_seconds' => 30,
            'daily_limit_per_user' => 3, 'is_active' => true,
        ]);

        // Today verified rows — must count.
        PtcView::create([
            'user_id' => $u->id, 'ptc_ad_id' => $ad->id,
            'epoch_token' => 'pv_today_'.uniqid(),
            'status' => 'verified', 'started_at' => Carbon::now()->subMinutes(5),
            'completed_at' => Carbon::now(), 'heartbeats_received' => 3,
            'heartbeats_expected' => 3,
        ]);
        ShortlinkClick::create([
            'user_id' => $u->id, 'provider_name' => 'mock',
            'reward_sat' => 5, 'hold_seconds' => 5,
            'epoch_token' => 'sc_today_'.uniqid(),
            'status' => 'verified', 'started_at' => Carbon::now()->subMinutes(5),
        ]);
        InternalArticleView::create([
            'user_id' => $u->id, 'internal_article_id' => $article->id,
            'reward_sat' => 1, 'read_seconds' => 30,
            'epoch_token' => 'ia_today_'.uniqid(),
            'status' => 'verified', 'started_at' => Carbon::now()->subMinutes(5),
        ]);

        // Today rejected — MUST NOT count (verified-only rule).
        ShortlinkClick::create([
            'user_id' => $u->id, 'provider_name' => 'mock',
            'reward_sat' => 5, 'hold_seconds' => 5,
            'epoch_token' => 'sc_rej_'.uniqid(),
            'status' => 'rejected', 'started_at' => Carbon::now()->subMinutes(5),
        ]);

        // Two days ago (outside both windows) — must not affect either side.
        // Backdate via forceFill since timestamps aren't mass-assignable.
        $oldView = PtcView::create([
            'user_id' => $u->id, 'ptc_ad_id' => $ad->id,
            'epoch_token' => 'pv_old_'.uniqid(),
            'status' => 'verified', 'started_at' => Carbon::now()->subDays(2),
            'completed_at' => Carbon::now()->subDays(2),
            'heartbeats_received' => 3, 'heartbeats_expected' => 3,
        ]);
        $oldView->forceFill([
            'created_at' => Carbon::now()->subDays(2),
            'updated_at' => Carbon::now()->subDays(2),
        ])->save();

        [$ptcStat, $shortlinkStat, $articleStat] = $this->extractStats(new EarningActivityWidget);
        $this->assertSame('1', $ptcStat->getValue());
        $this->assertSame('1', $shortlinkStat->getValue());
        $this->assertSame('1', $articleStat->getValue());
    }

    public function test_payout_volume_chart_only_includes_positive_deltas_in_window(): void
    {
        $u = User::factory()->create();
        // Two positive ledger rows today on different reasons.
        BalanceLedger::create([
            'user_id' => $u->id, 'delta_sat' => 100,
            'reason' => 'ptc_view', 'reference_type' => PtcView::class, 'reference_id' => 1,
        ]);
        BalanceLedger::create([
            'user_id' => $u->id, 'delta_sat' => 50,
            'reason' => 'shortlink', 'reference_type' => ShortlinkClick::class, 'reference_id' => 1,
        ]);
        // Negative delta (a withdrawal debit) — MUST NOT show in payout-out chart.
        BalanceLedger::create([
            'user_id' => $u->id, 'delta_sat' => -1000,
            'reason' => 'withdraw_request', 'reference_type' => Withdrawal::class, 'reference_id' => 1,
        ]);
        // Out-of-window row — must not affect today's bucket.
        // BalanceLedger doesn't include created_at in $fillable so we use
        // forceFill to backdate (mass-assignment ignores timestamp fields
        // by default).
        $old = BalanceLedger::create([
            'user_id' => $u->id, 'delta_sat' => 999,
            'reason' => 'ptc_view', 'reference_type' => PtcView::class, 'reference_id' => 2,
        ]);
        $old->forceFill([
            'created_at' => Carbon::now()->subDays(20),
            'updated_at' => Carbon::now()->subDays(20),
        ])->save();

        $data = $this->extractChartData(new PayoutVolumeChartWidget);

        $this->assertCount(14, $data['labels']);
        $this->assertCount(3, $data['datasets']);
        $ptcSeries = $data['datasets'][0]['data'];
        $shortSeries = $data['datasets'][1]['data'];
        // Today is the LAST entry in the 14-day window.
        $this->assertSame(100, end($ptcSeries));
        $this->assertSame(50, end($shortSeries));
        // No negative or out-of-window contamination.
        $this->assertSame(0, array_sum(array_slice($ptcSeries, 0, 13)));
        $this->assertSame(0, array_sum(array_slice($shortSeries, 0, 13)));
    }

    public function test_bot_tier_trend_chart_groups_evaluations_by_day_and_tier(): void
    {
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        // Today: one trust, two suspect, one banned evaluation.
        BotScoreHistory::create(['user_id' => $u1->id, 'score' => 0.10, 'tier' => 'trust', 'signals' => [], 'created_at' => Carbon::now()]);
        BotScoreHistory::create(['user_id' => $u1->id, 'score' => 0.40, 'tier' => 'suspect', 'signals' => [], 'created_at' => Carbon::now()]);
        BotScoreHistory::create(['user_id' => $u2->id, 'score' => 0.45, 'tier' => 'suspect', 'signals' => [], 'created_at' => Carbon::now()]);
        BotScoreHistory::create(['user_id' => $u2->id, 'score' => 0.95, 'tier' => 'banned', 'signals' => [], 'created_at' => Carbon::now()]);

        // Out-of-window row — must NOT contaminate today's bucket.
        $old = BotScoreHistory::create(['user_id' => $u1->id, 'score' => 0.99, 'tier' => 'banned', 'signals' => [], 'created_at' => Carbon::now()->subDays(20)]);
        $old->forceFill(['created_at' => Carbon::now()->subDays(20)])->save();

        $data = $this->extractChartData(new BotTierTrendChartWidget);

        $this->assertCount(14, $data['labels']);
        $this->assertCount(4, $data['datasets']);
        // dataset order = trust, suspect, likely_bot, banned.
        $trust = $data['datasets'][0]['data'];
        $suspect = $data['datasets'][1]['data'];
        $likelyBot = $data['datasets'][2]['data'];
        $banned = $data['datasets'][3]['data'];
        // Today is the LAST element.
        $this->assertSame(1, end($trust));
        $this->assertSame(2, end($suspect));
        $this->assertSame(0, end($likelyBot));
        $this->assertSame(1, end($banned));
        // Out-of-window 'banned' must not contaminate the 14-day window.
        $this->assertSame(1, array_sum($banned), 'only the in-window banned row should appear');
    }

    public function test_bot_tier_trend_chart_zero_state_returns_14_zero_filled_buckets(): void
    {
        $data = $this->extractChartData(new BotTierTrendChartWidget);

        $this->assertCount(14, $data['labels']);
        foreach ($data['datasets'] as $ds) {
            $this->assertCount(14, $ds['data']);
            $this->assertSame(0, array_sum($ds['data']));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChartData(object $widget): array
    {
        $m = new ReflectionMethod($widget, 'getData');
        $m->setAccessible(true);

        return $m->invoke($widget);
    }

    /**
     * Pulls the protected `getStats()` array out so we can assert against
     * it without spinning up a Livewire test harness for the rendering layer.
     *
     * @return array<int, Stat>
     */
    private function extractStats(object $widget): array
    {
        $m = new ReflectionMethod($widget, 'getStats');
        $m->setAccessible(true);

        return $m->invoke($widget);
    }

    private function observe(User $user, string $ip, int $hoursAgo = 1): void
    {
        $when = Carbon::now()->subHours($hoursAgo);
        UserIpObservation::create([
            'user_id' => $user->id,
            'ip' => $ip,
            'first_seen_at' => $when,
            'last_seen_at' => $when,
            'hit_count' => 1,
            'source' => 'login',
        ]);
    }
}
