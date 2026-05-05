<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\InternalArticleView;
use App\Models\PtcView;
use App\Models\ShortlinkClick;

/**
 * Lifecycle states shared by the three earning-session models
 * ({@see PtcView}, {@see ShortlinkClick},
 * {@see InternalArticleView}).
 *
 * Pre-enum the same four-string set was duplicated as bare strings on
 * each model and across every controller WHERE clause / comparison.
 * Centralising on a backed enum gives:
 *
 *   - PHPStan-verifiable equality (`$session->status === EarnSessionStatus::Pending`)
 *   - JSON serialisation produces the canonical lowercase value via
 *     Eloquent's enum cast — no ad-hoc `->value` calls needed
 *   - One place to add a future state (e.g. `Held` for ops triage)
 *
 * Eloquent reads the cast as the enum instance, so consumers compare
 * against the enum case directly. WHERE clauses and JSON inputs continue
 * to use the string value because the underlying column is still a
 * lowercase string.
 */
enum EarnSessionStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
