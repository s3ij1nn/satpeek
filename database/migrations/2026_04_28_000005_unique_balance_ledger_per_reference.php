<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Defence-in-depth against double-credit: at most one ledger row per
 * (reason, reference_type, reference_id) triple. The application code
 * already guards reward grants with an atomic `WHERE status=pending`
 * claim on the source row (PtcView, ShortlinkClick, …), but a future
 * refactor that drops the claim or fails to wrap the credit in a
 * transaction could silently double-pay. This index makes any such
 * regression a hard QueryException at the DB layer instead of a
 * silently-doubled balance.
 *
 * Scope: applies only when ALL THREE of `reason`, `reference_type`,
 * `reference_id` are non-null. That excludes operator manual
 * adjustments (which intentionally have null reference) and any
 * legacy rows pre-dating this index.
 *
 * Postgres uses a partial unique index. SQLite (test harness) treats
 * NULL values as distinct in unique indexes by default, so a plain
 * unique index has the same effective semantics.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX balance_ledgers_reason_reference_unique
                 ON balance_ledgers (reason, reference_type, reference_id)
                 WHERE reason IS NOT NULL
                   AND reference_type IS NOT NULL
                   AND reference_id IS NOT NULL'
            );
        } elseif ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX balance_ledgers_reason_reference_unique
                 ON balance_ledgers (reason, reference_type, reference_id)'
            );
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS balance_ledgers_reason_reference_unique');
        }
    }
};
