<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log of every ScoreEngine evaluation.
 *
 * `bot_scores` is updateOrCreate'd — only the LATEST evaluation
 * per user survives. That's right for the live "what's their tier
 * right now" lookup but loses the trend signal that operators
 * actually act on (a sudden suspect→likely_bot transition,
 * cohort-wide tier drift after a defence rollout, etc).
 *
 * This table keeps every evaluation as its own row, indexed on
 * `created_at` for the dashboard chart's date-range scan and on
 * `(user_id, created_at)` for per-user history pulls in
 * triage UI.
 *
 * Volume sizing: ScoreEngine throttles re-evaluation to once per
 * `bot_score.min_reevaluate_interval_seconds` (default 5 min) per
 * user. At 1k DAU that's ~12 rows/user/h ceiling = 288 k/day, which
 * Postgres handles trivially. Production deployments at higher
 * scale should partition or rotate via a daily cleanup command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_score_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('score', 5, 4);
            $table->string('tier', 16);
            $table->json('signals')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['tier', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_score_history');
    }
};
