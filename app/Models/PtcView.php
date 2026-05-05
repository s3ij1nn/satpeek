<?php

namespace App\Models;

use App\Enums\EarnSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $ptc_ad_id
 * @property string $epoch_token
 * @property EarnSessionStatus $status
 * @property string|null $rejection_reason
 * @property int $heartbeats_received
 * @property int $heartbeats_expected
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property array<string, mixed>|null $meta
 * @property-read User|null $user
 * @property-read PtcAd $ad
 */
class PtcView extends Model
{
    protected $fillable = [
        'user_id',
        'ptc_ad_id',
        'epoch_token',
        'status',
        'rejection_reason',
        'heartbeats_received',
        'heartbeats_expected',
        'started_at',
        'completed_at',
        'meta',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'status' => EarnSessionStatus::class,
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(PtcAd::class, 'ptc_ad_id');
    }
}
