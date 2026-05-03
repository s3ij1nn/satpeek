<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-tunable per-signal weight overrides.
 *
 * Defaults still live in `config('satpeek.bot_score.weights')` so a
 * fresh install boots with sensible numbers and an empty DB. When a
 * row exists here for a given signal name, the AppServiceProvider
 * merge step shadows the config default at runtime — no redeploy
 * needed to dial a noisy signal down or push a high-precision one
 * up. Mirrors the same merge pattern already used for
 * `offerwall_provider_settings` and `shortlink_provider_credentials`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_signal_weights', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->decimal('weight', 4, 3);
            $table->boolean('is_enabled')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_signal_weights');
    }
};
