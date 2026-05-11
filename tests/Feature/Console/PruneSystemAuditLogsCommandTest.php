<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\SystemAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PruneSystemAuditLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_rows_older_than_retention_window(): void
    {
        $now = Carbon::parse('2026-05-10 12:00:00');
        Carbon::setTestNow($now);

        // 95-day-old row → should prune at default 90-day retention.
        $old = SystemAuditLog::create([
            'source' => 'cron:test', 'level' => 'warning',
            'summary' => 'old', 'occurred_at' => $now->copy()->subDays(95),
        ]);
        $old->forceFill(['created_at' => $now->copy()->subDays(95)])->save();

        // 30-day-old row → keep.
        $young = SystemAuditLog::create([
            'source' => 'cron:test', 'level' => 'warning',
            'summary' => 'young', 'occurred_at' => $now->copy()->subDays(30),
        ]);
        $young->forceFill(['created_at' => $now->copy()->subDays(30)])->save();

        $this->artisan('satpeek:prune-system-audit-logs')
            ->expectsOutputToContain('pruned 1 rows older than 90 days')
            ->assertOk();

        $this->assertSame(1, SystemAuditLog::count());
        $this->assertSame('young', SystemAuditLog::first()->summary);

        Carbon::setTestNow();
    }

    public function test_dry_run_reports_count_without_deleting(): void
    {
        $now = Carbon::parse('2026-05-10 12:00:00');
        Carbon::setTestNow($now);

        $old = SystemAuditLog::create([
            'source' => 'cron:test', 'level' => 'warning',
            'summary' => 'old', 'occurred_at' => $now->copy()->subDays(100),
        ]);
        $old->forceFill(['created_at' => $now->copy()->subDays(100)])->save();

        $this->artisan('satpeek:prune-system-audit-logs', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run] would prune 1 rows')
            ->assertOk();

        $this->assertSame(1, SystemAuditLog::count());

        Carbon::setTestNow();
    }

    public function test_no_op_when_table_empty(): void
    {
        $this->artisan('satpeek:prune-system-audit-logs')
            ->expectsOutputToContain('nothing to prune')
            ->assertOk();
    }

    public function test_custom_days_override(): void
    {
        $now = Carbon::parse('2026-05-10 12:00:00');
        Carbon::setTestNow($now);

        // 10-day-old row → keep at default 90, prune at custom --days=5.
        $row = SystemAuditLog::create([
            'source' => 'cron:test', 'level' => 'warning',
            'summary' => 'tenDayOld', 'occurred_at' => $now->copy()->subDays(10),
        ]);
        $row->forceFill(['created_at' => $now->copy()->subDays(10)])->save();

        $this->artisan('satpeek:prune-system-audit-logs', ['--days' => 5])
            ->assertOk();

        $this->assertSame(0, SystemAuditLog::count());

        Carbon::setTestNow();
    }
}
