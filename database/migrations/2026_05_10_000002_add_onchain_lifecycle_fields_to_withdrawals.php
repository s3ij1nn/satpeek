<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-Phase-2b foundation: split the broadcast vs confirmed lifecycle
 * for onchain payouts, and lock down `onchain_tx_hash` as a unique
 * settlement key so the confirmation watcher can never settle the
 * same tx twice.
 *
 * Why now (before Tron signing lands): once Phase 2b ships and the
 * first onchain rows are settling, schema migrations under live
 * traffic become much higher-risk. Land the columns now while the
 * backfill is trivial (every existing row is FaucetPay-instant
 * settled, so `broadcast_at = confirmed_at = processed_at`).
 *
 * Schema additions:
 *   - `broadcast_at` — timestamp the gateway accepted the tx for
 *     relay (FaucetPay: HTTP 200; onchain: node returned txid).
 *     For FaucetPay this is identical to `processed_at` since the
 *     gateway returns the publisher's accepted state directly.
 *   - `confirmed_at` — timestamp the tx reached the chain's
 *     finality threshold (BTC 3 conf, ETH 12 conf, TRX 19 conf,
 *     etc.). For FaucetPay, equal to `broadcast_at`. For onchain,
 *     populated by the future `WatchOnchainConfirmationsJob`.
 *   - `confirmations_seen` — last observed confirmation count.
 *     Lets the operator see "stuck at 2/3" rather than just
 *     pending. 0 for FaucetPay rows.
 *
 * UNIQUE constraint:
 *   - `onchain_tx_hash` becomes UNIQUE. A confirmation watcher that
 *     re-processes a chain head re-stamping the same tx would get a
 *     constraint violation rather than silently double-settling.
 *     Partial-style: NULL is allowed (FaucetPay rows + pre-broadcast
 *     state). Postgres + SQLite both treat NULL as distinct in a
 *     UNIQUE column so multiple NULLs coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->timestamp('broadcast_at')->nullable()->after('processed_at');
            $table->timestamp('confirmed_at')->nullable()->after('broadcast_at');
            $table->unsignedInteger('confirmations_seen')->default(0)->after('confirmed_at');

            // UNIQUE so the confirmation watcher cannot stamp the same
            // tx hash onto two different withdrawal rows. Multiple
            // NULLs (FaucetPay rows, pre-broadcast onchain rows) are
            // distinct under both Postgres and SQLite UNIQUE semantics.
            $table->unique('onchain_tx_hash', 'withdrawals_onchain_tx_hash_unique');
        });

        // Backfill: every existing row is FaucetPay-instant-settled, so
        // broadcast == confirmed == processed_at. Gives operators
        // historical query parity with the new columns immediately
        // (e.g. "withdrawals confirmed in the last 7 days" works for
        // legacy rows too).
        DB::table('withdrawals')
            ->whereNotNull('processed_at')
            ->where('status', 'sent')
            ->update([
                'broadcast_at' => DB::raw('processed_at'),
                'confirmed_at' => DB::raw('processed_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropUnique('withdrawals_onchain_tx_hash_unique');
            $table->dropColumn(['broadcast_at', 'confirmed_at', 'confirmations_seen']);
        });
    }
};
