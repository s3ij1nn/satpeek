<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotScore extends Model
{
    protected $fillable = [
        'user_id',
        'score',
        'tier',
        'signals',
        'last_evaluated_at',
    ];

    protected $casts = [
        'score' => 'float',
        'signals' => 'array',
        'last_evaluated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
