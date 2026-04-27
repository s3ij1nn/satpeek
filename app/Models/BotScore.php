<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property float $score
 * @property string $tier
 * @property array<string, mixed>|null $signals
 * @property Carbon|null $last_evaluated_at
 * @property-read User|null $user
 */
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
