<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\SystemAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Pins the SystemAuditLog::record() helper. Two properties matter:
 *   - `record()` writes a row visible to subsequent queries
 *   - `record()` NEVER throws, even when the underlying DB write
 *     fails — a failing audit logger that masks the failure it's
 *     trying to capture is the worst possible behaviour
 */
class SystemAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_writes_a_row(): void
    {
        SystemAuditLog::record(
            source: 'cron:test',
            level: SystemAuditLog::LEVEL_WARNING,
            summary: 'Test row',
            detail: ['err' => 'sample'],
        );

        $this->assertSame(1, SystemAuditLog::count());
        $row = SystemAuditLog::first();
        $this->assertSame('cron:test', $row->source);
        $this->assertSame('warning', $row->level);
        $this->assertSame('Test row', $row->summary);
        $this->assertSame(['err' => 'sample'], $row->detail->toArray());
        $this->assertNotNull($row->occurred_at);
    }

    public function test_record_truncates_long_summary_to_500_chars(): void
    {
        // Summary column is varchar(500); truncate at the application
        // layer so a flapping cron doesn't wrap-and-wrap-and-wrap into
        // an Eloquent QueryException that itself becomes a logged
        // failure (cascading audit failure).
        SystemAuditLog::record(
            source: 'test',
            level: 'info',
            summary: str_repeat('a', 1000),
        );

        $row = SystemAuditLog::first();
        $this->assertSame(500, strlen($row->summary));
    }

    public function test_record_does_not_throw_on_db_failure(): void
    {
        // Drop the table mid-test to simulate the worst case (DB
        // unreachable / migration not yet applied / etc). The static
        // helper must swallow the exception and fall through to the
        // log channel — never throw out of record().
        Schema::drop('system_audit_logs');

        // No expectation of exception — if record() throws, this test
        // fails by exception escaping.
        SystemAuditLog::record('test', 'error', 'cant write');
        $this->assertTrue(true); // explicit pass marker
    }
}
