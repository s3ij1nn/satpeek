<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user IP audit log. Append-on-first-seen, update-last-seen otherwise.
 *
 * Driven by `App\Services\UserIpObserver`, which is invoked at the moment
 * authentication succeeds (login submit + registration submit + email
 * verification + password reset). The point is to keep the API quota
 * footprint tiny — a public landing-page hit never triggers a row.
 *
 * Used by `App\BotDetection\Signals\SharedIpSignal` to detect "this user
 * is signing in from an IP another account previously used", which is
 * the most common single-operator multi-account pattern. Cookie-only
 * dedup misses this when the operator clears cookies / uses incognito;
 * IP-only dedup misses when the operator rotates IP. Together they're
 * the two halves of a duplicate-account check.
 *
 * The composite (user_id, ip) UNIQUE makes upsert cheap and prevents
 * the table from blowing up on a noisy mobile-NAT user. The lone `ip`
 * index supports the cross-user lookup pattern
 * `WHERE ip = ? AND user_id != ?`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_ip_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 45);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('hit_count')->default(1);
            $table->string('source', 32)->default('login'); // login | register | verify | reset
            $table->timestamps();

            $table->unique(['user_id', 'ip']);
            $table->index('ip');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ip_observations');
    }
};
