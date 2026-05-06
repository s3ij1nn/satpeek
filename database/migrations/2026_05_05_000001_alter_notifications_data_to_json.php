<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coerces an already-deployed `notifications.data` column from `text`
 * to `json` on Postgres.
 *
 * Filament 4's database-notifications drawer filters on
 * `data->>'format' = 'filament'`. Postgres only exposes `->>` on
 * json/jsonb columns; on a `text` column the request 500s with
 * `operator does not exist: text ->> unknown`. The base `notifications`
 * migration shipped with `$table->text('data')` (matching the Laravel
 * `notifications:table` Artisan stub), so any environment that ran
 * migrations before this fix needs an explicit ALTER.
 *
 * Safe on existing rows: the column was already populated with
 * JSON-serialised payloads via Eloquent's array cast on the Notification
 * model, so the `USING data::json` cast is just a type relabel — no
 * data is rewritten or rejected.
 *
 * SQLite (the test driver) is a no-op because it has no static column
 * types — `->>` works on the same TEXT-affinity column unchanged. Same
 * reason this didn't trip the test suite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
    }
};
