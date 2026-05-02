<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per ScoreEngine evaluation. Append-only — never updated.
 *
 * `bot_scores` carries the live state; this carries the trail. See
 * the migration docblock for the rationale + volume-sizing notes.
 *
 * @property int $id
 * @property int|null $user_id
 * @property float $score
 * @property string $tier
 * @property array<string, mixed>|null $signals
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class BotScoreHistory extends Model
{
    /**
     * Append-only — no updated_at column on this table. Disabling Laravel's
     * automatic timestamp handling keeps the insert single-column and avoids
     * "field updated_at not found" errors when Eloquent tries to fill it.
     */
    public $timestamps = false;

    protected $table = 'bot_score_history';

    protected $fillable = [
        'user_id',
        'score',
        'tier',
        'signals',
        'created_at',
    ];

    protected $casts = [
        'score' => 'float',
        'signals' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
