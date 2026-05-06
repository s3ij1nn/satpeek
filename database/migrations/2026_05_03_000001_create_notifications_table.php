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
 * Schema mirrors Laravel's `notifications:table` Artisan output, with
 * one Postgres-driven deviation: `data` is `json` (not `text`).
 * Filament 4's database-notifications drawer filters on
 * `data->>'format' = 'filament'` and Postgres only exposes the
 * `->>` operator on json/jsonb columns — `text` triggers an
 * `operator does not exist: text ->> unknown` 500. The Laravel docs
 * still show `text` because SQLite + MySQL coerce silently, but on
 * Postgres a json column is mandatory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
