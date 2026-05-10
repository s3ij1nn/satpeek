<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SystemAuditLog — durable trail for cron + scheduled job failures
 * (architect's deferred D2 recommendation, finally landing in 0.29).
 *
 * Currently a stalled watcher only surfaces via the live `/up` probe
 * + dashboard widget; an audit trail lets the operator answer "what
 * happened on the night of [date]" after the fact instead of
 * grep-ing storage/logs.
 *
 * Schema choices:
 *   - source: free-form discriminator string (e.g. `cron:satpeek:hot-wallet-alert`,
 *     `job:WatchOnchainConfirmationsJob`). Indexed for the per-source
 *     filter on the Filament resource.
 *   - level: 'info' / 'warning' / 'error' — same vocabulary as Laravel
 *     Log::warn / Log::error so an operator reading the audit row +
 *     the matching log line gets coherent severity.
 *   - summary: one-line. Indexed for substring search? No — Postgres
 *     LIKE on text doesn't pay off without trigram, and the volume
 *     here is low. Filament's table search will linear-scan.
 *   - detail: jsonb (Postgres) — full context for debugging.
 *   - occurred_at: separate from created_at because some sources
 *     record events that happened slightly earlier (e.g. dead-letter
 *     callback runs after the actual failure).
 *
 * Retention: indefinite for now. Add a pruning command if volume
 * becomes a concern (typical row count is < 100/day even on a busy
 * deploy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 200);
            $table->string('level', 16);
            $table->string('summary', 500);
            $table->json('detail')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('source');
            $table->index('level');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_audit_logs');
    }
};
