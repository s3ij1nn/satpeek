<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 4, 3)->default(0);
            $table->string('tier', 16)->default('trust'); // trust|suspect|likely_bot|banned
            $table->json('signals')->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index('tier');
        });

        Schema::create('fingerprint_blacklist', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint_hash', 128)->unique();
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('ip_blacklist', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->unique();
            $table->string('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_blacklist');
        Schema::dropIfExists('fingerprint_blacklist');
        Schema::dropIfExists('bot_scores');
    }
};
