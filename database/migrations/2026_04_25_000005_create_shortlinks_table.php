<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shortlinks', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('external_id', 96);
            $table->string('title', 200);
            $table->string('target_url', 500);
            $table->unsignedBigInteger('reward_sat');
            $table->unsignedSmallInteger('hold_seconds')->default(10);
            $table->unsignedSmallInteger('daily_limit_per_user')->default(5);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index('is_active');
        });

        Schema::create('shortlink_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shortlink_id')->constrained()->cascadeOnDelete();
            $table->string('epoch_token', 96)->unique();
            $table->string('status', 16)->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shortlink_clicks');
        Schema::dropIfExists('shortlinks');
    }
};
