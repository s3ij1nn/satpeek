<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // High-volume table; partition or rotate in production.
        Schema::create('behavioral_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id', 128)->nullable();
            $table->string('kind', 24); // mouse|focus|key|heartbeat|fp
            $table->json('payload');
            $table->string('client_ip', 45)->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['user_id', 'observed_at']);
            $table->index(['session_id', 'observed_at']);
            $table->index(['kind', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavioral_events');
    }
};
