<?php

declare(strict_types=1);

namespace Tests\Feature\Withdraw;

use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the user-facing /withdraw view's multi-currency contract:
 *   - Currency picker contains every FaucetPay-supported code (BTC,
 *     LTC, ETH, USDT_TRC20, TRX, DASH, XMR) sourced from
 *     PayoutCurrencyRegistry, NOT a hardcoded list (the form was
 *     previously a static `['BTC','DOGE','LTC',...]` array).
 *   - The form uses the new field names (`destination`,
 *     `payout_currency`) so the post lines up with the multi-currency
 *     WithdrawController validator.
 *   - Recent withdrawals row shows the new `payout_currency` for new
 *     rows AND falls back to the legacy `currency` column for old
 *     rows (no schema break for pre-Phase-1 data).
 */
class WithdrawPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_currency_picker_lists_every_faucetpay_supported_currency(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->get('/withdraw');

        $response->assertOk();
        // Every Phase 1 currency must be in the dropdown — adding one
        // to config/satpeek.php must surface here on the next render.
        foreach (['BTC', 'LTC', 'ETH', 'USDT_TRC20', 'TRX', 'DASH', 'XMR'] as $code) {
            $response->assertSee('value="'.$code.'"', false);
        }
    }

    public function test_form_posts_use_destination_and_payout_currency_field_names(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->get('/withdraw');

        $response->assertSee('name="destination"', false);
        $response->assertSee('name="payout_currency"', false);
        $response->assertSee('name="amount_sat"', false);
        // Legacy field names must NOT appear — would mean the form
        // submits with the old shape and the controller validator
        // 422s every legitimate user.
        $response->assertDontSee('name="faucetpay_email"', false);
        $response->assertDontSee('name="currency"', false);
    }

    public function test_recent_withdrawals_handles_new_and_legacy_rows(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        // Legacy row — pre-Phase-1, no payout_currency / payout_amount.
        Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 5000,
            'faucetpay_email' => 'legacy@example.com',
            'currency' => 'BTC',
            'status' => 'sent',
            'faucetpay_payout_id' => 'PO-LEGACY',
        ]);
        // Phase 1 row.
        Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 8000,
            'payout_method' => 'faucetpay',
            'payout_currency' => 'USDT_TRC20',
            'payout_amount' => '4800000',
            'destination' => 'modern@example.com',
            'status' => 'sent',
            'faucetpay_payout_id' => 'PO-MODERN',
        ]);

        $response = $this->actingAs($user)->get('/withdraw');

        $response->assertOk();
        $response->assertSee('legacy@example.com');
        $response->assertSee('modern@example.com');
        $response->assertSee('USDT_TRC20', false);
        $response->assertSee('BTC', false);
    }

    public function test_unverified_user_sees_warning_and_disabled_form(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $response = $this->actingAs($user)->get('/withdraw');

        $response->assertOk();
        $response->assertSee('Verify your email first', false);
        // Inputs disabled prevents the user wasting the captcha solve
        // before realising the route is locked.
        $response->assertSee('disabled', false);
    }
}
