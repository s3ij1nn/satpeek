<?php

namespace App\Models;

use App\Enums\LedgerReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $delta_sat
 * @property LedgerReason $reason
 * @property string|null $external_ref
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property array<string, mixed>|null $meta
 * @property-read User|null $user
 */
class BalanceLedger extends Model
{
    /**
     * Canonical `reason` strings written into the ledger. New write
     * sites SHOULD prefer `LedgerReason::Foo` enum cases over the
     * `BalanceLedger::REASON_*` constants below — the cast on `reason`
     * accepts either form, but the enum is statically verifiable.
     *
     * Adding a new reason requires three coordinated edits:
     *   1. New `case Foo = 'foo';` in `App\Enums\LedgerReason`
     *   2. New `REASON_FOO` constant + `REASON_LABELS` entry in this
     *      class (constants stay for migration compatibility; LABELS
     *      is the single source of truth for the Filament filter
     *      dropdown via `BalanceLedgerResource`)
     *   3. Any analytics surface that does `whereIn('reason', [...])`
     *      with hardcoded values (e.g. `PayoutVolumeChartWidget`)
     *
     * A typo at any new write site that uses a bare string literal
     * silently routes a credit to the "unknown" bucket in the
     * operator filter dropdown and breaks tier-distribution analytics
     * — hence the strong preference for the enum form.
     */
    public const REASON_PTC_VIEW = 'ptc_view';

    public const REASON_SHORTLINK = 'shortlink';

    public const REASON_INTERNAL_ARTICLE = 'internal_article';

    public const REASON_BITCOTASK_POSTBACK = 'bitcotask_postback';

    public const REASON_REFERRAL_COMMISSION = 'referral_commission';

    public const REASON_WITHDRAW_REQUEST = 'withdraw_request';

    public const REASON_WITHDRAW_REFUND = 'withdraw_refund';

    public const REASON_WITHDRAW_REJECTED = 'withdraw_rejected';

    public const REASON_AD_FUNDING = 'ad_funding';

    public const REASON_AD_REFUND = 'ad_refund';

    public const REASON_MANUAL_CREDIT = 'manual_credit';

    public const REASON_MANUAL_DEBIT = 'manual_debit';

    /**
     * Display labels for every canonical reason — single source of truth
     * shared by the Filament filter dropdown and any future analytics
     * surface that wants a human-readable bucket name.
     *
     * @var array<string, string>
     */
    public const REASON_LABELS = [
        self::REASON_PTC_VIEW => 'PTC view',
        self::REASON_SHORTLINK => 'Shortlink click',
        self::REASON_INTERNAL_ARTICLE => 'Internal article read',
        self::REASON_BITCOTASK_POSTBACK => 'BitcoTask postback',
        self::REASON_REFERRAL_COMMISSION => 'Referral commission',
        self::REASON_WITHDRAW_REQUEST => 'Withdrawal request (debit)',
        self::REASON_WITHDRAW_REFUND => 'Withdrawal refund',
        self::REASON_WITHDRAW_REJECTED => 'Withdrawal rejected (refund)',
        self::REASON_AD_FUNDING => 'Ad funding (debit)',
        self::REASON_AD_REFUND => 'Ad refund',
        self::REASON_MANUAL_CREDIT => 'Manual credit (admin)',
        self::REASON_MANUAL_DEBIT => 'Manual debit (admin)',
    ];

    protected $fillable = [
        'user_id',
        'delta_sat',
        'reason',
        'external_ref',
        'reference_type',
        'reference_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'reason' => LedgerReason::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
