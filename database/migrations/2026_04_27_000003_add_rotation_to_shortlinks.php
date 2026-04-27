<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds per-click rotation support to shortlinks.
 *
 * Repeating the same shortened URL trains viewers to recognise + skip past it
 * — and, more importantly, lets browsers / extensions blocklist a single
 * stable string. Rotation defends against both: each click re-runs the
 * destination through the configured shortener, so the URL handed to the
 * viewer is fresh.
 *
 * Schema:
 *   - source_url            — canonical destination URL (the un-shortened
 *                             target the operator actually wants viewers to
 *                             reach). Backfilled from target_url for existing
 *                             rows so the rotation logic is a no-op until the
 *                             operator opts in via provider_name.
 *   - provider_name         — config key of the shortener used to issue the
 *                             current target_url. Null = static, no rotation.
 *   - target_url_rotated_at — last time the rotator wrote target_url. Used
 *                             by tests + admin display; the rotation cadence
 *                             itself is per-click, no TTL.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shortlinks', function (Blueprint $table) {
            $table->string('source_url', 500)->nullable()->after('target_url');
            $table->string('provider_name', 32)->nullable()->after('source_url');
            $table->timestamp('target_url_rotated_at')->nullable()->after('provider_name');
        });

        // Existing rows: source_url defaults to target_url so admin can
        // toggle on rotation by just setting provider_name later.
        DB::table('shortlinks')->whereNull('source_url')->update([
            'source_url' => DB::raw('target_url'),
        ]);
    }

    public function down(): void
    {
        Schema::table('shortlinks', function (Blueprint $table) {
            $table->dropColumn(['source_url', 'provider_name', 'target_url_rotated_at']);
        });
    }
};
