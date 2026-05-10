<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Widgets\SystemAuditLogTailWidget;
use App\Models\SystemAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the SystemAuditLogTailWidget visibility contract:
 *   - hidden on a clean deploy (no warning/error rows)
 *   - visible once at least one warning/error row exists
 *   - info-level rows alone do NOT make it visible
 */
class SystemAuditLogTailWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_hidden_on_clean_deploy(): void
    {
        $this->assertFalse(SystemAuditLogTailWidget::canView());
    }

    public function test_widget_visible_when_warning_row_exists(): void
    {
        SystemAuditLog::record('cron:test', SystemAuditLog::LEVEL_WARNING, 'first warning');
        $this->assertTrue(SystemAuditLogTailWidget::canView());
    }

    public function test_widget_visible_when_error_row_exists(): void
    {
        SystemAuditLog::record('job:test', SystemAuditLog::LEVEL_ERROR, 'something blew up');
        $this->assertTrue(SystemAuditLogTailWidget::canView());
    }

    public function test_widget_hidden_when_only_info_rows(): void
    {
        // info is noise on a dashboard; it only lands in the full
        // /admin/system-audit-logs resource, never in the tail widget.
        SystemAuditLog::record('cron:test', SystemAuditLog::LEVEL_INFO, 'normal heartbeat');
        $this->assertFalse(SystemAuditLogTailWidget::canView());
    }
}
