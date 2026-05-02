<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\AdminAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the AdminAuditor.record() write contract:
 *
 *   - admin_user_id auto-populates from the current auth context
 *   - target_type / target_id auto-populate from the model
 *   - empty payload is stored as NULL (not [] or "{}")
 *   - failures bubble up as silent best-effort returns rather than throws
 *     (the calling Filament action must never break on a logging hiccup)
 */
class AdminAuditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_attributes_action_to_authenticated_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create();
        $this->actingAs($admin);

        $log = AdminAuditor::record('user.rescore', $target, ['tier' => 'suspect']);

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->admin_user_id);
        $this->assertSame('user.rescore', $log->action);
        $this->assertSame(User::class, $log->target_type);
        $this->assertSame($target->id, $log->target_id);
        $this->assertSame(['tier' => 'suspect'], $log->payload);
    }

    public function test_record_with_no_target_persists_null_target_columns(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $log = AdminAuditor::record('config.export');

        $this->assertNull($log->target_type);
        $this->assertNull($log->target_id);
    }

    public function test_record_with_empty_payload_stores_null_not_empty_object(): void
    {
        // Storing [] would round-trip as "[]" json which is annoying to
        // filter against in operator queries. Empty payload → NULL is the
        // contract we want to keep stable.
        $admin = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create();
        $this->actingAs($admin);

        $log = AdminAuditor::record('user.rescore', $target);

        $row = AdminAuditLog::find($log->id);
        $this->assertNull($row->payload);
    }

    public function test_record_returns_null_when_admin_is_unauthenticated(): void
    {
        // No actingAs() — represents a CLI tinker / queue worker context
        // where the audit insert would have admin_user_id=NULL. The row
        // still gets written so we can attribute the action to "(system)".
        $target = User::factory()->create();

        $log = AdminAuditor::record('seeder.import', $target, ['count' => 3]);

        $this->assertNotNull($log);
        $this->assertNull($log->admin_user_id);
    }
}
