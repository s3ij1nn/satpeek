<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Widgets\BotTierDistributionWidget;
use App\Filament\Widgets\InFlightWithdrawalsWidget;
use App\Models\BotScore;
use App\Models\User;
use App\Models\Withdrawal;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
