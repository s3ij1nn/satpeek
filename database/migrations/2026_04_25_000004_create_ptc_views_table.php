<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptc_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ptc_ad_id')->constrained()->cascadeOnDelete();
            $table->string('epoch_token', 96)->unique();
            $table->string('status', 16)->default('pending'); // pending|verified|rejected|expired
            $table->string('rejection_reason')->nullable();
            $table->unsignedSmallInteger('heartbeats_received')->default(0);
            $table->unsignedSmallInteger('heartbeats_expected');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status']);
            $table->index(['ptc_ad_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptc_views');
    }
};
