<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_signups', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('faucetpay_email')->nullable();
            $table->string('referral_code', 16)->nullable()->index();
            $table->string('source', 64)->nullable()->index();
            $table->string('client_ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_signups');
    }
};
