<?php

declare(strict_types=1);

namespace Tests\Feature\Payout;

use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the Phase-2b foundation columns added in v0.18+:
 *   - broadcast_at / confirmed_at / confirmations_seen are persisted
 *     and round-trip as Carbon / int through the Eloquent cast pipeline
 *   - WithdrawalStatus::Broadcast exists and casts both directions
 *   - onchain_tx_hash UNIQUE refuses two rows with the same hash
 *     (the confirmation watcher's double-settle guard)
 *   - multiple NULL onchain_tx_hash rows coexist (FaucetPay rows +
 *     pre-broadcast onchain rows must not collide)
 */
class OnchainLifecycleSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_lifecycle_columns_persist_correctly(): void
    {
        $user = User::factory()->create();
        $row = Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 1000,
            'destination' => 'pay@example.com',
            'payout_method' => 'faucetpay',
            'payout_currency' => 'BTC',
            'status' => WithdrawalStatus::Broadcast,
            'broadcast_at' => '2026-05-10 12:00:00',
            'confirmed_at' => '2026-05-10 12:30:00',
            'confirmations_seen' => 3,
        ]);
        $row->refresh();

        $this->assertSame(WithdrawalStatus::Broadcast, $row->status);
        $this->assertNotNull($row->broadcast_at);
        $this->assertNotNull($row->confirmed_at);
        $this->assertSame(3, (int) $row->confirmations_seen);
    }

    public function test_unique_onchain_tx_hash_rejects_duplicate_settlement(): void
    {
        // Confirmation watcher MUST NOT be able to settle the same chain
        // tx onto two different withdrawal rows. The DB constraint is the
        // last-line-of-defence behind the watcher's own dedupe logic.
        $user = User::factory()->create();
        Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 1000,
            'destination' => 'pay@example.com',
            'payout_method' => 'onchain',
            'payout_currency' => 'TRX',
            'status' => WithdrawalStatus::Broadcast,
            'onchain_tx_hash' => '0xfedcba9876543210',
        ]);

        $this->expectException(QueryException::class);
        Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 2000,
            'destination' => 'pay2@example.com',
            'payout_method' => 'onchain',
            'payout_currency' => 'TRX',
            'status' => WithdrawalStatus::Broadcast,
            'onchain_tx_hash' => '0xfedcba9876543210',
        ]);
    }

    public function test_multiple_null_onchain_tx_hash_rows_coexist(): void
    {
        // Every FaucetPay row has onchain_tx_hash = NULL. Two of them
        // must not trip the UNIQUE — Postgres + SQLite both treat NULLs
        // as distinct in a UNIQUE column, but pin it explicitly so a
        // future schema change (e.g. partial-index variant) can't
        // silently break this.
        $user = User::factory()->create();
        Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 1000,
            'destination' => 'a@example.com',
            'payout_method' => 'faucetpay',
            'payout_currency' => 'BTC',
            'status' => WithdrawalStatus::Sent,
        ]);
        Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 2000,
            'destination' => 'b@example.com',
            'payout_method' => 'faucetpay',
            'payout_currency' => 'BTC',
            'status' => WithdrawalStatus::Sent,
        ]);

        $this->assertSame(2, Withdrawal::count());
    }

    public function test_broadcast_enum_case_round_trips(): void
    {
        $this->assertSame('broadcast', WithdrawalStatus::Broadcast->value);
        $this->assertSame(WithdrawalStatus::Broadcast, WithdrawalStatus::from('broadcast'));
    }
}
