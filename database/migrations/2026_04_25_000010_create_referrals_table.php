<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('lifetime_commission_sat')->default(0);
            $table->timestamps();

            $table->unique(['referrer_id', 'referred_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
