<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaptchaChallenge extends Model
{
    protected $fillable = [
        'challenge_id',
        'user_id',
        'session_id',
        'provider',
        'seed',
        'expected_shape',
        'fingerprint_hash',
        'client_ip',
        'ja4',
        'user_agent',
        'status',
        'rejection_reason',
        'issued_at',
        'expires_at',
        'resolved_at',
        'meta',
    ];

    protected $casts = [
        'expected_shape' => 'array',
        'meta' => 'array',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
