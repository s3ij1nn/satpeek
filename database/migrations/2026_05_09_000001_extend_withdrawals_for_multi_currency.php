<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of the multi-currency / multi-route payout work.
 *
 * Schema changes are purely additive on this pass — the legacy
 * `currency` and `faucetpay_email` columns stay so existing rows
 * continue to deserialize on their existing read paths. New columns:
 *
 *   - `payout_method` ('faucetpay' | 'onchain') — selects the gateway
 *     `ProcessWithdrawalJob` dispatches to. Phase 1 only ships
 *     'faucetpay'; 'onchain' is reserved for the per-chain gateways
 *     (Phase 2+).
 *   - `payout_currency` — explicit currency code (BTC / LTC / ETH /
 *     USDT_TRC20 / DASH / XMR / TRX). Replaces the loose `currency`
 *     column whose value space was undocumented.
 *   - `payout_amount` — amount expressed in `payout_currency`'s
 *     smallest unit (sats for BTC, wei for ETH, the TRC20 contract
 *     decimal for USDT_TRC20, etc.). What actually goes on the wire
 *     to FaucetPay / onchain. Distinct from `amount_sat` which
 *     remains the BTC-sat figure debited from the user's balance —
 *     the source-of-truth for ledger accounting.
 *   - `payout_rate` — decimal BTC-sat-per-1-payout-currency-unit at
 *     withdrawal time, captured from PriceOracle. Stored so a refund
 *     six hours later doesn't apply a different rate, and so support
 *     can reproduce the exact conversion the user saw.
 *   - `destination` — generic recipient field. Email for FaucetPay,
 *     wallet address for onchain. Backfilled from `faucetpay_email`.
 *   - `fee_sat` — operator fee on onchain payouts (gas markup,
 *     network fee bake-in). 0 for FaucetPay (FP charges no fee on
 *     the publisher side at the time of writing).
 *   - `onchain_tx_hash` — null for FaucetPay rows; populated by the
 *     onchain gateways after broadcast.
 *
 * Backfill: every existing row gets payout_method='faucetpay',
 * payout_currency=upper(currency) || 'BTC', destination=faucetpay_email.
 * payout_amount + payout_rate are left null on legacy rows because
 * they couldn't be retroactively computed against historical price
 * data; only new rows carry the conversion record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->string('payout_method', 16)->default('faucetpay')->after('amount_sat');
            $table->string('payout_currency', 16)->nullable()->after('payout_method');
            // decimal(36, 0) — ETH at 18 decimals × multi-BTC user balance
            // overflows signed-64-bit (max ≈9.2e18 wei = ~9.2 ETH). 36 digits
            // gives us 10^36 headroom, enough for any plausible payout
            // expressed in any plausible currency's smallest unit. Eloquent
            // returns this as a string — callers cast to int only when they
            // know the value fits (BTC sat amounts always do; ETH wei may not).
            $table->decimal('payout_amount', 36, 0)->nullable()->after('payout_currency');
            // 30-digit decimal with 18 fractional places covers ETH-scale
            // BTC-sats-per-wei conversions (extremely small) and BTC-scale
            // BTC-sats-per-USDT-unit (extremely large) without precision loss.
            $table->decimal('payout_rate', 30, 18)->nullable()->after('payout_amount');
            $table->string('destination', 200)->nullable()->after('payout_rate');
            $table->unsignedBigInteger('fee_sat')->default(0)->after('destination');
            $table->string('onchain_tx_hash', 200)->nullable()->after('faucetpay_payout_id');

            $table->index(['payout_method', 'status'], 'withdrawals_method_status_idx');
        });

        // Relax NOT NULL on the legacy fields. Onchain rows (Phase 2+)
        // legitimately have no faucetpay_email — they pay to a wallet
        // address via `destination` instead. The legacy `currency`
        // column likewise has no meaning for an onchain row keyed by
        // `payout_currency`. doctrine/dbal-style change() works the
        // same on Postgres + SQLite via Laravel's Schema builder.
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->string('faucetpay_email')->nullable()->change();
            $table->string('currency')->nullable()->change();
        });

        // Backfill — single UPDATE per column so the migration is fast on
        // a multi-million-row table. coalesce() keeps any pre-existing
        // explicit value (defensive, this column is brand new so all rows
        // pick the default).
        DB::table('withdrawals')->update([
            'destination' => DB::raw('coalesce(destination, faucetpay_email)'),
            'payout_currency' => DB::raw("coalesce(payout_currency, upper(coalesce(currency, 'BTC')))"),
            'payout_method' => DB::raw("coalesce(payout_method, 'faucetpay')"),
        ]);
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropIndex('withdrawals_method_status_idx');
            $table->dropColumn([
                'payout_method',
                'payout_currency',
                'payout_amount',
                'payout_rate',
                'destination',
                'fee_sat',
                'onchain_tx_hash',
            ]);
        });
    }
};
