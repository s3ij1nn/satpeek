<?php

declare(strict_types=1);

namespace Tests\Feature\Payout;

use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\Withdrawal;
use App\Payout\Tron\TronHttpClient;
use App\Payout\Tron\TronRpcException;
use App\Payout\WatchOnchainConfirmationsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Pins the Broadcast → Sent promotion contract:
 *   - Tx in a block with confirmations >= TRX_FINALITY (19) → status flips
 *     to Sent, confirmed_at stamped, confirmations_seen recorded.
 *   - Tx in a block with confirmations < threshold → status stays
 *     Broadcast, confirmations_seen ticks up, confirmed_at stays NULL.
 *   - Tx not yet in a block → no DB write at all.
 *   - getNowBlock failure → entire run aborts (don't poison every row
 *     with confirmations=0).
 *   - Per-row tx-info failure → that row skipped, others continue.
 *   - FaucetPay rows are NEVER touched (status=Broadcast doesn't apply
 *     to them but defence-in-depth — explicit method filter).
 *   - Already-Sent rows aren't re-promoted (atomic settle predicate
 *     filters status='broadcast').
 */
class WatchOnchainConfirmationsJobTest extends TestCase
{
    use RefreshDatabase;

    private const TX_HASH = 'cafebabe1234567890abcdef';

    public function test_promotes_broadcast_to_sent_when_finality_reached(): void
    {
        $w = $this->makeBroadcastWithdrawal();

        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('getNowBlock')->once()->andReturn(65000020);
        $http->shouldReceive('getTransactionInfo')->with(self::TX_HASH)->once()
            ->andReturn(['blockNumber' => 65000000]); // 21 confirmations
        $this->app->instance(TronHttpClient::class, $http);

        (new WatchOnchainConfirmationsJob)->handle($http);

        $w->refresh();
        $this->assertSame(WithdrawalStatus::Sent, $w->status);
        $this->assertNotNull($w->confirmed_at);
        $this->assertSame(21, (int) $w->confirmations_seen);
    }

    public function test_below_finality_just_ticks_counter(): void
    {
        $w = $this->makeBroadcastWithdrawal();

        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('getNowBlock')->once()->andReturn(65000005);
        $http->shouldReceive('getTransactionInfo')->with(self::TX_HASH)->once()
            ->andReturn(['blockNumber' => 65000000]); // 6 confirmations
        $this->app->instance(TronHttpClient::class, $http);

        (new WatchOnchainConfirmationsJob)->handle($http);

        $w->refresh();
        $this->assertSame(WithdrawalStatus::Broadcast, $w->status);
        $this->assertNull($w->confirmed_at);
        $this->assertSame(6, (int) $w->confirmations_seen);
    }

    public function test_tx_not_in_block_no_db_write(): void
    {
        $w = $this->makeBroadcastWithdrawal(['confirmations_seen' => 0]);

        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('getNowBlock')->once()->andReturn(65000020);
        $http->shouldReceive('getTransactionInfo')->with(self::TX_HASH)->once()
            ->andReturn([]); // empty body = not in block yet
        $this->app->instance(TronHttpClient::class, $http);

        (new WatchOnchainConfirmationsJob)->handle($http);

        $w->refresh();
        $this->assertSame(WithdrawalStatus::Broadcast, $w->status);
        $this->assertSame(0, (int) $w->confirmations_seen);
    }

    public function test_chain_head_failure_aborts_run_without_writes(): void
    {
        // A stuck oracle would otherwise mark every row as
        // "0 confirmations" and stall forever. Pin that getNowBlock
        // failure short-circuits BEFORE any per-row work.
        $w = $this->makeBroadcastWithdrawal(['confirmations_seen' => 5]);

        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('getNowBlock')->once()
            ->andThrow(new TronRpcException('all rpc unreachable'));
        // getTransactionInfo MUST NOT be called when head fetch fails.
        $http->shouldNotReceive('getTransactionInfo');
        $this->app->instance(TronHttpClient::class, $http);

        (new WatchOnchainConfirmationsJob)->handle($http);

        $w->refresh();
        $this->assertSame(WithdrawalStatus::Broadcast, $w->status);
        $this->assertSame(5, (int) $w->confirmations_seen); // unchanged
    }

    public function test_per_row_rpc_failure_skips_that_row_continues_others(): void
    {
        $w1 = $this->makeBroadcastWithdrawal(['onchain_tx_hash' => 'aaa']);
        $w2 = $this->makeBroadcastWithdrawal(['onchain_tx_hash' => 'bbb']);

        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('getNowBlock')->once()->andReturn(65000020);
        $http->shouldReceive('getTransactionInfo')->with('aaa')->once()
            ->andThrow(new TronRpcException('rpc 5xx'));
        $http->shouldReceive('getTransactionInfo')->with('bbb')->once()
            ->andReturn(['blockNumber' => 65000000]); // 21 conf → promote
        $this->app->instance(TronHttpClient::class, $http);

        (new WatchOnchainConfirmationsJob)->handle($http);

        $w1->refresh();
        $w2->refresh();
        $this->assertSame(WithdrawalStatus::Broadcast, $w1->status);
        $this->assertSame(WithdrawalStatus::Sent, $w2->status);
    }

    public function test_trc20_revert_refunds_user_and_marks_failed(): void
    {
        // TRC20 contract calls can be in a block but REVERT (insufficient
        // contract balance, paused contract, blacklisted recipient).
        // The watcher MUST refund the user's debit and mark the row
        // failed — leaving it as "sent" would silently steal balance.
        $user = User::factory()->create(['balance_sat' => 0]);
        $w = Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 1000,
            'payout_amount' => '1000000',
            'destination' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'payout_method' => Withdrawal::METHOD_ONCHAIN_USDT_TRC20,
            'payout_currency' => 'USDT_TRC20',
            'status' => 'broadcast',
            'onchain_tx_hash' => 'reverted-tx',
            'broadcast_at' => '2026-05-10 12:00:00',
        ]);

        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('getNowBlock')->once()->andReturn(65000020);
        $http->shouldReceive('getTransactionInfo')->with('reverted-tx')->once()
            ->andReturn([
                'blockNumber' => 65000000,
                'receipt' => ['result' => 'REVERT', 'energy_usage_total' => 14000],
            ]);
        $this->app->instance(TronHttpClient::class, $http);

        (new WatchOnchainConfirmationsJob)->handle($http);

        $w->refresh();
        $user->refresh();
        $this->assertSame(WithdrawalStatus::Failed, $w->status);
        $this->assertStringContainsString('REVERT', (string) $w->failure_reason);
        // User got a refund equal to amount_sat (NOT payout_amount —
        // the debit was the BTC-sat side of the original ledger row).
        $this->assertSame(1000, (int) $user->balance_sat);
    }

    public function test_faucetpay_rows_are_not_touched(): void
    {
        $user = User::factory()->create();
        $fp = Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 1000,
            'destination' => 'pay@example.com',
            'payout_method' => Withdrawal::METHOD_FAUCETPAY,
            'payout_currency' => 'BTC',
            'status' => 'broadcast', // bogus state for FP — defence-in-depth check
            'onchain_tx_hash' => null,
        ]);

        $http = Mockery::mock(TronHttpClient::class);
        // No rows for the watcher to process → getNowBlock NEVER called.
        $http->shouldNotReceive('getNowBlock');
        $http->shouldNotReceive('getTransactionInfo');
        $this->app->instance(TronHttpClient::class, $http);

        (new WatchOnchainConfirmationsJob)->handle($http);

        $fp->refresh();
        // Still bogus 'broadcast' — the watcher's responsibility is
        // strictly onchain rows; it MUST NOT touch FP rows even if a
        // bug elsewhere flipped a FP row's status.
        $this->assertSame(WithdrawalStatus::Broadcast, $fp->status);
    }

    private function makeBroadcastWithdrawal(array $overrides = []): Withdrawal
    {
        $user = User::factory()->create();

        return Withdrawal::create(array_merge([
            'user_id' => $user->id,
            'amount_sat' => 1000,
            'payout_amount' => '1000000',
            'destination' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'payout_method' => Withdrawal::METHOD_ONCHAIN_TRX,
            'payout_currency' => 'TRX',
            'status' => 'broadcast',
            'onchain_tx_hash' => self::TX_HASH,
            'broadcast_at' => '2026-05-10 12:00:00',
        ], $overrides));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
