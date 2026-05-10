<?php

declare(strict_types=1);

namespace Tests\Feature\Withdraw;

use App\Models\User;
use App\Models\Withdrawal;
use App\Payout\Gateway\PayoutGateway;
use App\Payout\Gateway\PayoutGatewayRegistry;
use App\Payout\Gateway\PayoutResult;
use App\Payout\PayoutConversion;
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
        // Mock the oracle to avoid hitting CoinGecko. Returns a
        // PayoutConversion (was a positional tuple — the named VO
        // closes the slot-swap risk). Controller persists targetAmount
        // verbatim into payout_amount and rateSatPerUnit into payout_rate.
        $this->mock(PriceOracle::class, function ($mock) {
            $mock->shouldReceive('convertBtcSatToTarget')
                ->with(50000, 'USDT_TRC20')
                ->andReturn(new PayoutConversion('1000', '5000'));
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

    public function test_legacy_onchain_method_string_is_rejected(): void
    {
        // The bare 'onchain' placeholder is no longer routable — every
        // chain has its own per-method gateway name now (onchain_trx,
        // future onchain_btc / onchain_eth / onchain_usdt_trc20). The
        // validator's allowed-methods list comes from the gateway
        // registry, so a generic 'onchain' string can never reach the
        // dispatcher with no matching gateway.
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

    public function test_onchain_trx_method_accepted_when_gateway_registered(): void
    {
        // Wire up the TRX onchain gateway via the registry singleton so
        // the controller's allowed-methods check picks it up. We don't
        // exercise the gateway itself here (that's TronOnchainGatewayTest);
        // we just prove the controller surfaces the method when the
        // gateway is present, validates the destination as a Tron
        // address, persists payout_method=onchain_trx, and debits the
        // user's balance.
        $registry = app(PayoutGatewayRegistry::class);
        $stubGateway = new class implements PayoutGateway
        {
            public function name(): string
            {
                return 'onchain_trx';
            }

            public function send(Withdrawal $withdrawal): PayoutResult
            {
                return PayoutResult::sent('stub-txid', 'stub', []);
            }
        };
        $registry->register($stubGateway);

        // Mock PriceOracle so the test doesn't hit CoinGecko.
        $oracle = Mockery::mock(PriceOracle::class);
        $oracle->shouldReceive('convertBtcSatToTarget')
            ->once()
            ->andReturn(new PayoutConversion('5000000', '1234567'));
        $this->app->instance(PriceOracle::class, $oracle);

        $user = User::factory()->create(['balance_sat' => 100_000]);

        $response = $this->actingAs($user)->postJson('/api/withdraw', [
            'amount_sat' => 50_000,
            'payout_method' => 'onchain_trx',
            'payout_currency' => 'TRX',
            // Real Base58Check-valid Tron address.
            'destination' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
        ]);

        $response->assertStatus(202);
        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $user->id,
            'payout_method' => 'onchain_trx',
            'payout_currency' => 'TRX',
            'destination' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            // faucetpay_email NEVER populated for onchain rows.
            'faucetpay_email' => null,
        ]);
        $this->assertSame(50_000, (int) $user->fresh()->balance_sat);
    }

    public function test_onchain_trx_rejects_non_tron_destination(): void
    {
        $registry = app(PayoutGatewayRegistry::class);
        $registry->register(new class implements PayoutGateway
        {
            public function name(): string
            {
                return 'onchain_trx';
            }

            public function send(Withdrawal $w): PayoutResult
            {
                return PayoutResult::sent('x', '', []);
            }
        });

        $user = User::factory()->create(['balance_sat' => 100_000]);

        $response = $this->actingAs($user)->postJson('/api/withdraw', [
            'amount_sat' => 50_000,
            'payout_method' => 'onchain_trx',
            'payout_currency' => 'TRX',
            // BTC bech32, not a Tron address — must be rejected before
            // any DB row is created.
            'destination' => 'bc1qxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'invalid_destination');
        $this->assertSame(100_000, (int) $user->fresh()->balance_sat);
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
