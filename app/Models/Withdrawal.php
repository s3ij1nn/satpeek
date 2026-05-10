<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $amount_sat
 * @property string $payout_method
 * @property string|null $payout_currency
 * @property int|null $payout_amount
 * @property string|null $payout_rate
 * @property string|null $destination
 * @property int $fee_sat
 * @property string|null $faucetpay_email legacy — pre-multi-currency rows
 * @property string|null $currency legacy — pre-multi-currency rows
 * @property WithdrawalStatus $status
 * @property string|null $faucetpay_payout_id
 * @property string|null $onchain_tx_hash
 * @property string|null $failure_reason
 * @property bool $requires_review
 * @property int|null $reviewed_by
 * @property Carbon|null $processed_at
 * @property array<string, mixed>|null $meta
 * @property-read User|null $user
 * @property-read User|null $reviewer
 */
class Withdrawal extends Model
{
    public const METHOD_FAUCETPAY = 'faucetpay';

    public const METHOD_ONCHAIN = 'onchain';

    protected $fillable = [
        'user_id',
        'amount_sat',
        'payout_method',
        'payout_currency',
        'payout_amount',
        'payout_rate',
        'destination',
        'fee_sat',
        'faucetpay_email',
        'currency',
        'status',
        'faucetpay_payout_id',
        'onchain_tx_hash',
        'failure_reason',
        'requires_review',
        'reviewed_by',
        'processed_at',
        'meta',
    ];

    protected $casts = [
        'requires_review' => 'boolean',
        'processed_at' => 'datetime',
        'status' => WithdrawalStatus::class,
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
