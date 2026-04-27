<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-managed enable/disable flag for offerwall publisher integrations
 * (BitcoTasks today). Lets the admin panel flip a partner on the moment
 * the publisher review approves API access — no redeploy, no queue
 * worker restart, no .env edit.
 *
 * Credentials intentionally stay in the env / config. This row only owns
 * the boolean flag plus an operator notes field. Putting the bearer token
 * here would widen the secret-leak surface (DB dumps, replicas, backups)
 * without the same hardening as env-stage secrets typically get.
 *
 * Resolution: AdapterRegistry merges this table over
 * config('satpeek.offerwalls.enabled') — see App\Providers\AppServiceProvider.
 * If a row's `is_enabled = true`, the adapter participates even if the env
 * list is empty. If `is_enabled = false`, the adapter is excluded even
 * when the env list contains it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offerwall_provider_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();
            $table->boolean('is_enabled')->default(false);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offerwall_provider_settings');
    }
};
