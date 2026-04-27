<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $challenge_id
 * @property int|null $user_id
 * @property string|null $session_id
 * @property string $provider
 * @property string $seed
 * @property array<int, array{x: float, y: float, t: float}> $expected_shape
 * @property string|null $fingerprint_hash
 * @property string|null $client_ip
 * @property string|null $ja4
 * @property string|null $user_agent
 * @property string $status
 * @property string|null $rejection_reason
 * @property Carbon $issued_at
 * @property Carbon $expires_at
 * @property Carbon|null $resolved_at
 * @property array<string, mixed>|null $meta
 */
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
