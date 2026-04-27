<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $ip
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property int $hit_count
 * @property string $source
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 */
class UserIpObservation extends Model
{
    protected $fillable = [
        'user_id',
        'ip',
        'first_seen_at',
        'last_seen_at',
        'hit_count',
        'source',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'hit_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
