<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Smoke + content-shape locks for the public landing page.
 *
 * Two pinned behaviours:
 *   - the route resolves and returns 200 anonymously (no auth wall)
 *   - the value-strip surfaces LIVE platform stats when there's
 *     enough data to be meaningful, falling back to static "what we
 *     are" labels on a fresh install (so the page doesn't show
 *     "0 sat paid to users" right after launch — that would be a
 *     trust-anti-signal)
 */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_landing_page_renders_anonymously(): void
    {
        $response = $this->get('/');

        $response->assertOk();
    }

    public function test_value_strip_falls_back_to_static_labels_when_no_payouts_yet(): void
    {
        // Brand-new install: no withdrawals sent. The total-sat-paid
        // cell must show the static "Withdraw via FaucetPay" label
        // instead of "0 sat paid to users" (that would actively
        // discourage signups).
        $response = $this->get('/');

        $response->assertSee('Withdraw via FaucetPay', false);
        $response->assertDontSee('Paid to users', false);
    }

    public function test_value_strip_surfaces_live_paid_total_once_payouts_exist(): void
    {
        $u = User::factory()->create();
        Withdrawal::create(['user_id' => $u->id, 'amount_sat' => 12345, 'faucetpay_email' => 'a@x', 'currency' => 'BTC', 'status' => 'sent']);

        $response = $this->get('/');

        $response->assertSee('Paid to users', false);
        $response->assertSee('12,345', false);
    }
}
