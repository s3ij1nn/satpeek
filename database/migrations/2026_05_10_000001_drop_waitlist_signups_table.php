<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the `waitlist_signups` table.
 *
 * The waitlist controller / model / mail / views were never wired into
 * `routes/web.php` or `routes/api.php` — the feature shipped as a
 * scaffold and was never used. The whole stack is removed in this
 * release; this migration cleans up the unused table on environments
 * that ran the original create migration.
 *
 * `dropIfExists` so a fresh setup (where the create migration was
 * already removed before this drop ran) still passes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('waitlist_signups');
    }

    public function down(): void
    {
        // Intentional no-op — re-creating the table would be useless
        // because all the model / controller code that wrote to it is
        // gone. A future "we want a waitlist" feature should ship its
        // own create migration with whatever schema it needs.
    }
};
