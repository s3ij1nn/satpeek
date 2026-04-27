<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $delta_sat
 * @property string $reason
 * @property string|null $external_ref
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property array<string, mixed>|null $meta
 * @property-read User|null $user
 */
class BalanceLedger extends Model
{
    protected $fillable = [
        'user_id',
        'delta_sat',
        'reason',
        'external_ref',
        'reference_type',
        'reference_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
