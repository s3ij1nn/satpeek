<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency key for inbound S2S postbacks.
 *
 * Postback providers (BitcoTask, future offerwalls) re-send the same
 * `transId` on retry — sometimes for hours after the original. Without an
 * idempotency check the user double-credits. Storing the upstream
 * transaction ID per (reason, external_ref) pair lets us short-circuit
 * the second arrival without an EXISTS query against the JSON `meta`
 * column (which has no index and degrades at scale).
 *
 * Nullable so existing legacy rows without an upstream ref keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('balance_ledgers', function (Blueprint $table) {
            $table->string('external_ref', 96)->nullable()->after('reason');
            // Composite uniqueness: same provider's same transaction ID
            // can never land twice. NULLs are treated as distinct in both
            // postgres and mysql, so legacy rows aren't affected.
            $table->unique(['reason', 'external_ref'], 'balance_ledgers_reason_external_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('balance_ledgers', function (Blueprint $table) {
            $table->dropUnique('balance_ledgers_reason_external_ref_unique');
            $table->dropColumn('external_ref');
        });
    }
};
