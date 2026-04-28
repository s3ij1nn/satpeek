<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-shape shortlinks around the provider, not the inventory row.
 *
 * Old model (deprecated as of v0.5.x):
 *   The operator pre-populated `shortlinks` rows. Each row carried
 *   reward/hold/limit and either pointed at a static target or was
 *   re-shortened per click. Filament had two pages — Shortlinks
 *   (inventory) AND Shortener APIs (credentials).
 *
 * New model (this migration):
 *   The earning flow is "user clicks → SatPeek mints a fresh
 *   /shortlinks/auth/{token} URL → shortens through provider X →
 *   user completes provider X's interstitial → comes back to the
 *   token URL → paid". There IS no inventory — only providers.
 *   Filament collapses to one page (Shortlink providers) where each
 *   row is the provider's API credential PLUS its per-click economics
 *   (reward / hold / daily limit per user).
 *
 * Schema changes:
 *
 *   shortlink_provider_credentials:
 *     + reward_sat              INT      DEFAULT 5
 *     + hold_seconds            SMALLINT DEFAULT 5
 *     + daily_limit_per_user    SMALLINT DEFAULT 10
 *
 *   shortlink_clicks:
 *     + provider_name           VARCHAR(32) NULL  (NOT NULL for new clicks)
 *     + reward_sat              INT         NULL  (snapshot at click creation)
 *     + hold_seconds            SMALLINT    NULL  (snapshot at click creation)
 *     ~ shortlink_id            now NULLABLE     (legacy column; no new clicks reference it)
 *
 * Snapshotting reward + hold on the click row keeps the auth-landing
 * payout self-contained — a future operator config tweak doesn't
 * retroactively change unfinished clicks' rewards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortlink_provider_credentials', function (Blueprint $table) {
            $table->unsignedInteger('reward_sat')->default(5)->after('is_active');
            $table->unsignedSmallInteger('hold_seconds')->default(5)->after('reward_sat');
            $table->unsignedSmallInteger('daily_limit_per_user')->default(10)->after('hold_seconds');
        });

        Schema::table('shortlink_clicks', function (Blueprint $table) {
            $table->string('provider_name', 32)->nullable()->after('shortlink_id')->index();
            $table->unsignedInteger('reward_sat')->nullable()->after('provider_name');
            $table->unsignedSmallInteger('hold_seconds')->nullable()->after('reward_sat');
        });

        // Drop NOT NULL on shortlink_id so new provider-keyed clicks
        // can omit it. Postgres takes the in-place ALTER; SQLite needs
        // Laravel's portable change() which transparently rebuilds the
        // table. (Without the SQLite branch the in-memory test DB still
        // enforces NOT NULL and breaks all click-creating tests.)
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            Schema::getConnection()->statement(
                'ALTER TABLE shortlink_clicks ALTER COLUMN shortlink_id DROP NOT NULL'
            );
        } elseif ($driver === 'sqlite') {
            Schema::table('shortlink_clicks', function (Blueprint $table) {
                $table->unsignedBigInteger('shortlink_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('shortlink_clicks', function (Blueprint $table) {
            $table->dropIndex(['provider_name']);
            $table->dropColumn(['provider_name', 'reward_sat', 'hold_seconds']);
        });
        Schema::table('shortlink_provider_credentials', function (Blueprint $table) {
            $table->dropColumn(['reward_sat', 'hold_seconds', 'daily_limit_per_user']);
        });
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'ALTER TABLE shortlink_clicks ALTER COLUMN shortlink_id SET NOT NULL'
            );
        }
    }
};
