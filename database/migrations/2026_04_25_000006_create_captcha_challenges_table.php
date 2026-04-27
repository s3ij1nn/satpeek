<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captcha_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('challenge_id', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id', 128)->nullable();
            $table->string('provider', 32);
            $table->string('seed', 64);
            $table->json('expected_shape');           // sampled control points of canonical curve
            $table->string('fingerprint_hash', 128)->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->string('ja4', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('status', 16)->default('issued'); // issued|verified|rejected|expired
            $table->string('rejection_reason')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('resolved_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captcha_challenges');
    }
};
