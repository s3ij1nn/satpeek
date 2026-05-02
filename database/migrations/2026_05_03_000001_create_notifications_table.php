<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard Laravel notifications table — backs Filament's database
 * notifications drawer. We dispatch to admin Users when a non-admin
 * user's bot tier escalates (trust → suspect → likely_bot → banned)
 * so the operator sees enforcement events without polling the
 * /admin/users index.
 *
 * Schema follows Laravel's `notifications:table` Artisan output
 * verbatim so a future framework migration drops in cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
