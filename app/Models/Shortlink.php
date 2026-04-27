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
        'source_url',
        'provider_name',
        'target_url_rotated_at',
        'reward_sat',
        'hold_seconds',
        'daily_limit_per_user',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'target_url_rotated_at' => 'datetime',
    ];

    /** Whether `start` should re-shorten via the configured provider on click. */
    public function rotates(): bool
    {
        return ! empty($this->provider_name) && ! empty($this->source_url);
    }
}
