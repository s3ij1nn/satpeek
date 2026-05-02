<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal "read & earn" article inventory + per-click view rows.
 *
 * Why this exists alongside the BitcoTask offerwall:
 *   - BitcoTask passthrough is great when a per-user adapter is wired,
 *     but operators often want admin-managed editorial content too —
 *     curated articles, blog posts, announcements, sponsored reads.
 *   - The internal rows render INSIDE SatPeek (Markdown body), which
 *     means we can verify the user actually sat on our page for the
 *     advertised read_seconds before unlocking the captcha. External
 *     URLs can't give us that signal.
 *
 * Schema mirrors the shortlink + PTC shape on purpose:
 *   - reward_sat / read_seconds / daily_limit_per_user live on the
 *     parent inventory row
 *   - per-view row snapshots reward + read_seconds at start time so a
 *     mid-flight admin tweak can't retroactively change unfinished
 *     views' rewards
 *   - status pending → verified | rejected | expired (single-use
 *     epoch_token, atomic-claim guarded at /complete time)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('body'); // Markdown source; rendered safely at view time.
            $table->string('source_attribution', 200)->nullable();
            $table->unsignedInteger('reward_sat')->default(10);
            $table->unsignedSmallInteger('read_seconds')->default(45);
            $table->unsignedSmallInteger('daily_limit_per_user')->default(3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'created_at']);
        });

        Schema::create('internal_article_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('internal_article_id')->constrained()->cascadeOnDelete();
            $table->string('epoch_token', 40)->unique();
            $table->unsignedInteger('reward_sat'); // snapshot
            $table->unsignedSmallInteger('read_seconds'); // snapshot
            $table->string('status', 16)->default('pending'); // pending|verified|rejected|expired
            $table->string('rejection_reason', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_article_views');
        Schema::dropIfExists('internal_articles');
    }
};
