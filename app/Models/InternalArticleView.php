<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per click on an internal read-article task.
 *
 * `reward_sat` + `read_seconds` are snapshotted at start time so a
 * mid-flight admin tweak to the parent article can't retroactively
 * change unfinished views' payouts.
 *
 * @property int $id
 * @property int $user_id
 * @property int $internal_article_id
 * @property string $epoch_token
 * @property int $reward_sat snapshot
 * @property int $read_seconds snapshot
 * @property string $status pending|verified|rejected|expired
 * @property string|null $rejection_reason
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property-read User $user
 * @property-read InternalArticle $article
 */
class InternalArticleView extends Model
{
    protected $fillable = [
        'user_id',
        'internal_article_id',
        'epoch_token',
        'reward_sat',
        'read_seconds',
        'status',
        'rejection_reason',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'reward_sat' => 'integer',
        'read_seconds' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(InternalArticle::class, 'internal_article_id');
    }
}
