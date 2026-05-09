<?php

declare(strict_types=1);

namespace Tests\Feature\Withdraw;

use App\Models\User;
use App\Payout\PriceOracle;
use App\Payout\PriceOracleUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * End-to-end multi-currency contract for `POST /api/withdraw`:
 *   - BTC route accepts and persists with payout_currency='BTC'
 *   - USDT-TRC20 route converts and persists payout_amount/rate
 *   - Unknown currency rejected
 *   - Onchain method rejected (Phase 1 only ships FaucetPay)
 *   - Oracle outage returns 503 (no balance debit)
 *   - Below-min returns 422 with currency-specific floor
 */
class MultiCurrencyWithdrawTest extends TestCase
{
    use RefreshDatabase;

    public function test_btc_withdrawal_skips_oracle_and_persists_correctly(): void
    {
        $user = User::factory()->create([
            'balance_sat' => 50000,
            'is_admin' => false,
        ]);

        $response = $this->actingAs($user)->postJson('/api/withdraw', [
            'amount_sat' => 10000,
            'payout_currency' => 'BTC',
            'destination' => 'pay@example.com',
        ]);

        $response->assertStatus(202);
        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $user->id,
            'amount_sat' => 10000,
            'payout_method' => 'faucetpay',
            'payout_currency' => 'BTC',
            // PriceOracle returns the BTC→BTC identity as a string;
            // Eloquent's decimal cast keeps it that shape on read.
            'payout_amount' => '10000',
            'destination' => 'pay@example.com',
            'fee_sat' => 0,
        ]);
        $this->assertSame(40000, (int) $user->fresh()->balance_sat);
    }

    public function test_usdt_trc20_withdrawal_uses_oracle_for_conversion(): void
    {
        // Mock the oracle to avoid hitting CoinGecko. Returns 1000 USDT
        // smallest-unit + a bogus rate string — controller persists the
        // tuple verbatim. Both elements are decimal strings now (ETH
        // wei overflows int64; PriceOracle returns strings).
        $this->mock(PriceOracle::class, function ($mock) {
            $mock->shouldReceive('convertBtcSatToTarget')
                ->with(50000, 'USDT_TRC20')
                ->andReturn(['1000', '5000']);
        });

        $user = User::factory()->create(['balance_sat' => 100000]);

        $response = $this->actingAs($user)->postJson('/api/withdraw', [
            'amount_sat' => 50000,
            'payout_currency' => 'USDT_TRC20',
            'destination' => 'pay@example.com',
        ]);

        $response->assertStatus(202);
        $response->assertJsonFragment([
            'payout_currency' => 'USDT_TRC20',
            'payout_amount' => '1000',
        ]);
        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $user->id,
            'payout_currency' => 'USDT_TRC20',
            'payout_amount' => '1000',
        ]);
    }

    public function test_unknown_currency_is_rejected(): void
    {
        $user = User::factory()->create(['balance_sat' => 50000]);

        $response = $this->actingAs($user)->postJson('/api/withdraw', [
            'amount_sat' => 10000,
            'payout_currency' => 'FAKECOIN',
            'destination' => 'pay@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payout_currency']);
    }

    public function test_onchain_method_is_rejected_in_phase_1(): void
    {
        // Phase 1 ships only FaucetPay; the validator's Rule::in
        // intentionally excludes 'onchain' until per-chain gateways register.
        $user = User::factory()->create(['balance_sat' => 50000]);

        $response = $this->actingAs($user)->postJson('/api/withdraw', [
            'amount_sat' => 10000,
            'payout_method' => 'onchain',
            'payout_currency' => 'BTC',
            'destination' => 'bc1qxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payout_method']);
    }

    public function test_oracle_outage_returns_503_without_debiting_balance(): void
    {
        $this->mock(PriceOracle::class, function ($mock) {
            $mock->shouldReceive('convertBtcSatToTarget')
                ->andThrow(new PriceOracleUnavailableException('coingecko down'));
        });

        $user = User::factory()->create(['balance_sat' => 50000]);

        $response = $this->actingAs($user)->postJson('/api/withdraw', [
            'amount_sat' => 10000,
            'payout_currency' => 'USDT_TRC20',
            'destination' => 'pay@example.com',
        ]);

        $response->assertStatus(503);
        $response->assertJsonFragment(['error' => 'price_oracle_unavailable']);
        // Critical: no balance debit on outage — user retries fresh.
        $this->assertSame(50000, (int) $user->fresh()->balance_sat);
        $this->assertDatabaseMissing('withdrawals', ['user_id' => $user->id]);
    }

    public function test_below_currency_minimum_is_rejected_with_currency_specific_floor(): void
    {
        // ETH minimum is 5000 BTC sats per config; 1000 should fail.
        $user = User::factory()->create(['balance_sat' => 50000]);

        $response = $this->actingAs($user)->postJson('/api/withdraw', [
            'amount_sat' => 1000,
            'payout_currency' => 'ETH',
            'destination' => 'pay@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'error' => 'below_minimum',
            'currency' => 'ETH',
            'min_sat' => 5000,
        ]);
    }

    public function test_faucetpay_destination_must_look_like_email(): void
    {
        $user = User::factory()->create(['balance_sat' => 50000]);

        $response = $this->actingAs($user)->postJson('/api/withdraw', [
            'amount_sat' => 10000,
            'payout_currency' => 'BTC',
            'destination' => 'bc1qnotanemail',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'invalid_destination']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
