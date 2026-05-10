<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical reason codes for `balance_ledger` rows.
 *
 * v0.10.0 introduced `BalanceLedger::REASON_*` string constants which
 * closed the typo-at-write-site risk for the constants we'd already
 * named — but a future write site that uses a bare string literal
 * (`'ptc_view'` instead of `BalanceLedger::REASON_PTC_VIEW`) still
 * silently routes credits to an "unknown" bucket in the operator
 * filter dropdown. This enum + the Eloquent cast on `BalanceLedger.reason`
 * make every new write site PHPStan-verifiable: the only acceptable
 * values are the cases below.
 *
 * The string `value` of each case mirrors the existing
 * `BalanceLedger::REASON_*` constant for migration compatibility —
 * no DB rewrite needed; existing rows continue to deserialize.
 *
 * `BalanceLedger::REASON_LABELS` and the existing `BalanceLedger::REASON_*`
 * constants stay for one release as a thin alias layer so any external
 * caller that imported the constants directly keeps working. Future
 * releases drop the constants once internal callers are migrated.
 */
enum LedgerReason: string
{
    case PtcView = 'ptc_view';
    case Shortlink = 'shortlink';
    case InternalArticle = 'internal_article';
    case BitcotaskPostback = 'bitcotask_postback';
    case ReferralCommission = 'referral_commission';
    case WithdrawRequest = 'withdraw_request';
    case WithdrawRefund = 'withdraw_refund';
    case WithdrawRejected = 'withdraw_rejected';
    case AdFunding = 'ad_funding';
    case AdRefund = 'ad_refund';
    case ManualCredit = 'manual_credit';
    case ManualDebit = 'manual_debit';
}
