<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 32)->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('faucetpay_email')->nullable();
            $table->unsignedBigInteger('balance_sat')->default(0);
            $table->unsignedBigInteger('total_earned_sat')->default(0);
            $table->unsignedBigInteger('total_withdrawn_sat')->default(0);
            $table->foreignId('referrer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('referral_code', 16)->unique();
            $table->string('registration_ip', 45)->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_banned')->default(false);
            $table->string('ban_reason')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index('referrer_id');
            $table->index('is_banned');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
