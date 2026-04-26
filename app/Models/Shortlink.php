<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shortlink extends Model
{
    protected $fillable = [
        'source',
        'external_id',
        'title',
        'target_url',
        'reward_sat',
        'hold_seconds',
        'daily_limit_per_user',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
