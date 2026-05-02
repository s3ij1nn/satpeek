<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of operator actions taken inside /admin.
 *
 * Why this exists:
 *   - Compliance / forensics. When a user disputes a ban or a
 *     payout reversal, the operator needs to know who took the
 *     action and when, not just that the row's status is "rejected".
 *   - Detect rogue admin behaviour. With a single shared admin
 *     account the diff between "approved by Alice yesterday" and
 *     "approved by Bob just now" is invisible without this trail.
 *   - Surface enforcement velocity in the dashboard. Pairs with
 *     `BotTierTrendChartWidget` to give the operator a "what
 *     happened in the last 14 days" answer.
 *
 * Schema choices:
 *   - `admin_user_id` is nullable + nullOnDelete so a removed admin
 *     account doesn't cascade-wipe the trail (the action stays
 *     attributable to "(deleted admin)").
 *   - `target_type` + `target_id` form a polymorphic pointer at the
 *     row the action mutated. Indexed jointly so per-target history
 *     pulls (e.g. "show me everything that happened to user #42")
 *     are O(log n).
 *   - `payload` json captures the before/after diff or context
 *     (rejection reason, balance delta, etc) so triage doesn't
 *     have to cross-reference the live row's mutated state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('target_type', 64)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['target_type', 'target_id']);
            $table->index(['admin_user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_log');
    }
};
