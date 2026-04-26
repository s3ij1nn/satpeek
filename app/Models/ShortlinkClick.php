<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShortlinkClick extends Model
{
    protected $fillable = [
        'user_id',
        'shortlink_id',
        'epoch_token',
        'status',
        'rejection_reason',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shortlink(): BelongsTo
    {
        return $this->belongsTo(Shortlink::class);
    }
}
