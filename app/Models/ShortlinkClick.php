<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $shortlink_id
 * @property string $epoch_token
 * @property string $status
 * @property string|null $rejection_reason
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property-read User|null $user
 * @property-read Shortlink $shortlink
 */
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
