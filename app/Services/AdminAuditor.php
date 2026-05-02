<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Single entry point for writing rows to `admin_audit_log`.
 *
 * Centralising attribution (admin_user_id from the auth guard,
 * client_ip from the request) means call sites in Filament actions
 * stay one-liners and no resource has to remember to thread the
 * context through. Wrapping the actual insert in try/catch keeps
 * the audit-log layer best-effort — a logging failure must never
 * block the live operator action.
 */
class AdminAuditor
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(string $action, ?Model $target = null, array $payload = []): ?AdminAuditLog
    {
        try {
            return AdminAuditLog::create([
                'admin_user_id' => Auth::id(),
                'action' => $action,
                'target_type' => $target ? $target::class : null,
                'target_id' => $target?->getKey(),
                'payload' => $payload === [] ? null : $payload,
                'client_ip' => Request::ip(),
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable) {
            // Best-effort. Don't break the admin action if the log
            // table is missing (mid-migration tinker) or the insert
            // fails for any reason.
            return null;
        }
    }
}
