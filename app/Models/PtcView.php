<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PtcView extends Model
{
    protected $fillable = [
        'user_id',
        'ptc_ad_id',
        'epoch_token',
        'status',
        'rejection_reason',
        'heartbeats_received',
        'heartbeats_expected',
        'started_at',
        'completed_at',
        'meta',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(PtcAd::class, 'ptc_ad_id');
    }
}
