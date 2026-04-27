<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-managed credentials for URL-shortener publisher APIs (btcut, ouo,
 * cuty, exe, shrtfly…). Was previously env-only; this table lets the admin
 * panel rotate keys without redeploying. The api_token column is encrypted
 * at rest via the model cast.
 *
 * The `name` column is the same key used by config('satpeek.shortlink_providers')
 * — config still drives label / transport / api_base defaults so adding a new
 * provider only requires a config entry plus an admin filling the token.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shortlink_provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();
            $table->string('label', 64)->nullable();
            $table->string('transport', 16)->default('query'); // 'query' | 'path'
            $table->string('api_base', 255);
            $table->text('api_token')->nullable(); // encrypted via model cast
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shortlink_provider_credentials');
    }
};
