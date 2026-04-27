<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `iframe` embeds the ad inline (works only for sites without
     * X-Frame-Options/CSP frame-ancestors restrictions). `window` opens the
     * ad in a separate tab on a user gesture — required for ads whose
     * destination top-frame-redirects or refuses to embed. Default is
     * `window` because it works everywhere; iframe is opt-in for trusted
     * embeddable destinations.
     */
    public function up(): void
    {
        Schema::table('ptc_ads', function (Blueprint $table) {
            $table->string('display_mode', 16)->default('window')->after('target_url');
        });
    }

    public function down(): void
    {
        Schema::table('ptc_ads', function (Blueprint $table) {
            $table->dropColumn('display_mode');
        });
    }
};
