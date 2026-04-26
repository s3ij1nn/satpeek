<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
