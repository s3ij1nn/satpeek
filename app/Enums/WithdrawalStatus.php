<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle states of a `withdrawals` row.
 *
 * Replaces the bare string literals scattered across `ProcessWithdrawalJob`
 * (queued / processing / hold / sent / failed / rejected) and the Filament
 * resource. Pre-enum, a typo in any WHERE clause silently skipped a row
 * with no PHPStan signal — and the financial code paths in
 * ProcessWithdrawalJob and the admin reject/approve actions all gate on
 * the status string. With the enum, every comparison is type-checked.
 *
 * Eloquent reads the cast as the enum instance, so consumers compare
 * against the enum case directly. WHERE clauses MAY still pass the
 * string value (Laravel's Query Builder doesn't apply Eloquent casts to
 * `where()` arguments) but it's cleaner to pass `WithdrawalStatus::Queued->value`
 * for symmetry and PHPStan safety. The underlying column stays a
 * lowercase string so existing rows + reporting queries are unchanged.
 */
enum WithdrawalStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Hold = 'hold';
    case Sent = 'sent';
    case Failed = 'failed';
    case Rejected = 'rejected';
}
