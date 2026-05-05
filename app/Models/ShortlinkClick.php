<?php

namespace App\Models;

use App\Enums\EarnSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per shortlink click attempt.
 *
 * `provider_name` + `reward_sat` + `hold_seconds` are snapshotted at
 * click creation so a later operator-config tweak can't retroactively
 * change unfinished clicks' rewards. `shortlink_id` is legacy
 * (nullable for new provider-keyed clicks); kept on the schema so
 * existing rows continue to resolve.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $shortlink_id legacy — null on provider-keyed clicks
 * @property string|null $provider_name
 * @property int|null $reward_sat snapshotted from provider at start
 * @property int|null $hold_seconds snapshotted from provider at start
 * @property string $epoch_token
 * @property EarnSessionStatus $status
 * @property string|null $rejection_reason
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property-read User|null $user
 * @property-read Shortlink|null $shortlink
 */
class ShortlinkClick extends Model
{
    protected $fillable = [
        'user_id',
        'shortlink_id',
        'provider_name',
        'reward_sat',
        'hold_seconds',
        'epoch_token',
        'status',
        'rejection_reason',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'status' => EarnSessionStatus::class,
        'reward_sat' => 'integer',
        'hold_seconds' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shortlink(): BelongsTo
    {
        return $this->belongsTo(Shortlink::class);
    }

    /**
     * Effective reward for this click — prefers the snapshotted value
     * (provider-keyed flow), falls back to the legacy parent Shortlink
     * row's column for old data.
     */
    public function effectiveRewardSat(): int
    {
        if ($this->reward_sat !== null) {
            return (int) $this->reward_sat;
        }

        return (int) ($this->shortlink->reward_sat ?? 0);
    }

    /**
     * Effective hold duration. Same fallback shape as the reward
     * accessor — snapshot first, legacy parent next.
     */
    public function effectiveHoldSeconds(): int
    {
        if ($this->hold_seconds !== null) {
            return (int) $this->hold_seconds;
        }

        return (int) ($this->shortlink->hold_seconds ?? 0);
    }
}
