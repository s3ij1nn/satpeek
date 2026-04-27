<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $amount_sat
 * @property string $faucetpay_email
 * @property string $currency
 * @property string $status
 * @property string|null $faucetpay_payout_id
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
    protected $fillable = [
        'user_id',
        'amount_sat',
        'faucetpay_email',
        'currency',
        'status',
        'faucetpay_payout_id',
        'failure_reason',
        'requires_review',
        'reviewed_by',
        'processed_at',
        'meta',
    ];

    protected $casts = [
        'requires_review' => 'boolean',
        'processed_at' => 'datetime',
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
