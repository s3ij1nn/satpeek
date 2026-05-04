<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ShortlinkController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite index that the per-(user, provider) daily-limit guard hits on
 * every /shortlinks/start/{provider} call.
 *
 * The query in {@see ShortlinkController::start()}:
 *
 *   SELECT count(*) FROM shortlink_clicks
 *   WHERE user_id = ? AND provider_name = ? AND status = 'verified'
 *     AND created_at >= ?
 *
 * was satisfied by the existing `(user_id, created_at)` index but Postgres
 * still had to filter the matching range by provider_name + status in the
 * heap. As shortlink_clicks grows past a few million rows the click latency
 * starts being dominated by that scan. Compositing the leading-equality
 * columns first (user_id, provider_name, status) and the range column last
 * (created_at) lets the planner walk a single index range. Same shape on
 * SQLite — covering index, no heap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortlink_clicks', function (Blueprint $table) {
            $table->index(
                ['user_id', 'provider_name', 'status', 'created_at'],
                'shortlink_clicks_daily_limit_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('shortlink_clicks', function (Blueprint $table) {
            $table->dropIndex('shortlink_clicks_daily_limit_idx');
        });
    }
};
