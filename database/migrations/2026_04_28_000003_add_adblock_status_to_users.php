<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user adblock detection state.
 *
 *   - `adblock_status`:
 *       null       — never reported (treat as `unchecked` until proven clean)
 *       'clean'    — last frontend probe found no adblock + not Brave
 *       'detected' — adblock or Brave shields detected
 *   - `adblock_checked_at`: the moment the last probe report landed.
 *     Stale (older than the configured TTL) is treated as `detected`
 *     by `App\Http\Middleware\AdblockGate` so a bot that simply
 *     never POSTs the report can't bypass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('adblock_status', 16)->nullable()->after('is_banned');
            $table->timestamp('adblock_checked_at')->nullable()->after('adblock_status');
            $table->index('adblock_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['adblock_status']);
            $table->dropColumn(['adblock_status', 'adblock_checked_at']);
        });
    }
};
