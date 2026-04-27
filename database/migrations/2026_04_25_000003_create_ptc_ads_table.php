<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptc_ads', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('external_id', 96);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('target_url', 500);
            $table->unsignedBigInteger('reward_sat');
            $table->unsignedSmallInteger('duration_sec');
            $table->unsignedSmallInteger('daily_limit_per_user')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index(['is_active', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptc_ads');
    }
};
