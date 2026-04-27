<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends `ptc_ads` so end users can submit their own ads (affiliate links, own
 * site promos, etc.) using the same account they use to view ads.
 *
 * Existing admin-managed rows (source = 'internal' / 'mock') stay backward-
 * compatible: `user_id` stays null, `status` defaults to 'approved', and the
 * legacy `is_active` flag continues to gate visibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ptc_ads', function (Blueprint $table) {
            // The advertiser. Null = admin / system inventory.
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();

            // Pricing — what the advertiser paid per view (reward + commission).
            $table->unsignedBigInteger('cost_per_view_sat')->default(0)->after('reward_sat');

            // Budget bookkeeping.
            $table->unsignedInteger('total_views_purchased')->default(0)->after('cost_per_view_sat');
            $table->unsignedInteger('views_remaining')->default(0)->after('total_views_purchased');
            $table->unsignedBigInteger('total_cost_sat')->default(0)->after('views_remaining');

            // Workflow.
            $table->string('status', 16)->default('approved')->after('total_cost_sat')->index();
            $table->text('submission_notes')->nullable()->after('status');
            $table->string('rejection_reason')->nullable()->after('submission_notes');
            $table->timestamp('approved_at')->nullable()->after('rejection_reason');
            $table->foreignId('reviewed_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
        });

        // Backfill: every existing row was admin-created → keep them visible.
        DB::table('ptc_ads')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '');
            })
            ->update(['status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('ptc_ads', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn([
                'user_id', 'cost_per_view_sat', 'total_views_purchased', 'views_remaining',
                'total_cost_sat', 'status', 'submission_notes', 'rejection_reason',
                'approved_at', 'reviewed_by',
            ]);
        });
    }
};
