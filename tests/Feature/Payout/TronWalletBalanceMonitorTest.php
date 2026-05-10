<?php

declare(strict_types=1);

namespace Tests\Feature\Payout;

use App\Models\User;
use App\Models\Withdrawal;
use App\Payout\Tron\TronHttpClient;
use App\Payout\Tron\TronRpcException;
use App\Payout\Tron\TronUsdtWalletBalanceMonitor;
use App\Payout\Tron\TronWalletBalanceMonitor;
use App\Payout\WalletBalanceUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Pins both Tron-family monitors against the operator-dashboard
 * contract: available() reports the chain-side balance,
 * required() sums in-flight withdrawal payout_amount, RPC failure
 * surfaces as WalletBalanceUnavailableException so the widget
 * can render "(unavailable)" instead of a misleading zero.
 */
class TronWalletBalanceMonitorTest extends TestCase
{
    use RefreshDatabase;

    private const HOT_WALLET = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    private const USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    public function test_trx_available_reads_balance_from_getaccount(): void
    {
        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('getAccount')->with(self::HOT_WALLET)->once()
            ->andReturn(['balance' => 1_500_000_000, 'frozen' => []]);

        $monitor = new TronWalletBalanceMonitor($http, self::HOT_WALLET);
        $this->assertSame('1500000000', $monitor->available()); // 1500 TRX in sun
        $this->assertSame('TRX', $monitor->currency());
    }

    public function test_trx_available_returns_zero_for_fresh_wallet(): void
    {
        // /wallet/getaccount returns {} for an address with no
        // on-chain history. Treat as zero, NOT throw — the wallet
        // genuinely has zero balance.
        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('getAccount')->once()->andReturn([]);

        $monitor = new TronWalletBalanceMonitor($http, self::HOT_WALLET);
        $this->assertSame('0', $monitor->available());
    }

    public function test_trx_available_throws_unavailable_on_rpc_failure(): void
    {
        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('getAccount')->once()
            ->andThrow(new TronRpcException('all rpc unreachable'));

        $monitor = new TronWalletBalanceMonitor($http, self::HOT_WALLET);

        $this->expectException(WalletBalanceUnavailableException::class);
        $monitor->available();
    }

    public function test_trx_required_sums_in_flight_payout_amount(): void
    {
        $user = User::factory()->create();
        $this->makeOnchainTrxWithdrawal($user, 'queued', '500000');
        $this->makeOnchainTrxWithdrawal($user, 'broadcast', '1500000');
        // hold + processing also counted (operator's runway view
        // wants the WORST case, i.e. everything that might settle).
        $this->makeOnchainTrxWithdrawal($user, 'hold', '300000');
        $this->makeOnchainTrxWithdrawal($user, 'processing', '200000');
        // sent + failed must NOT count — already settled / refunded.
        $this->makeOnchainTrxWithdrawal($user, 'sent', '99999');
        $this->makeOnchainTrxWithdrawal($user, 'failed', '11111');
        // FaucetPay row must NOT count — different gateway.
        Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 1000,
            'payout_amount' => '777',
            'destination' => 'pay@example.com',
            'payout_method' => Withdrawal::METHOD_FAUCETPAY,
            'payout_currency' => 'TRX',
            'status' => 'queued',
        ]);

        $http = Mockery::mock(TronHttpClient::class);
        $monitor = new TronWalletBalanceMonitor($http, self::HOT_WALLET);

        // 500000 + 1500000 + 300000 + 200000 = 2_500_000
        $this->assertSame('2500000', $monitor->required());
    }

    public function test_usdt_available_parses_balanceof_constant_result(): void
    {
        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('triggerConstantContract')
            ->withArgs(function ($owner, $contract, $selector, $param) {
                return $owner === self::HOT_WALLET
                    && $contract === self::USDT_CONTRACT
                    && $selector === 'balanceOf(address)'
                    && strlen($param) === 64;
            })
            ->once()
            ->andReturn([
                // 1_000_000 = 0xF4240 in hex (1.0 USDT in 6-decimal
                // base units), padded to 64 chars.
                'constant_result' => [str_pad('f4240', 64, '0', STR_PAD_LEFT)],
            ]);

        $monitor = new TronUsdtWalletBalanceMonitor($http, self::HOT_WALLET, self::USDT_CONTRACT);
        $this->assertSame('1000000', $monitor->available());
        $this->assertSame('USDT_TRC20', $monitor->currency());
    }

    public function test_usdt_available_throws_when_constant_result_missing(): void
    {
        // A misconfigured contract address would return an empty
        // constant_result. Surface as unavailable so the widget
        // shows "(unavailable)" — better than a wrong zero.
        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('triggerConstantContract')->once()
            ->andReturn(['constant_result' => []]);

        $monitor = new TronUsdtWalletBalanceMonitor($http, self::HOT_WALLET, self::USDT_CONTRACT);

        $this->expectException(WalletBalanceUnavailableException::class);
        $monitor->available();
    }

    private function makeOnchainTrxWithdrawal(User $user, string $status, string $amount): void
    {
        Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 1000,
            'payout_amount' => $amount,
            'destination' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'payout_method' => Withdrawal::METHOD_ONCHAIN_TRX,
            'payout_currency' => 'TRX',
            'status' => $status,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
