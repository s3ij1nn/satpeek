<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Admin-curated read-and-earn article.
 *
 * Body is stored as raw Markdown; rendering happens at view time
 * (resources/views/internal_articles/auth.blade.php) via a sandboxed
 * Markdown converter so an attacker uploading a payload can't XSS
 * other readers. `reward_sat` + `read_seconds` + `daily_limit_per_user`
 * are operator-tunable per article.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property string|null $source_attribution
 * @property int $reward_sat
 * @property int $read_seconds
 * @property int $daily_limit_per_user
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class InternalArticle extends Model
{
    protected $fillable = [
        'title',
        'body',
        'source_attribution',
        'reward_sat',
        'read_seconds',
        'daily_limit_per_user',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'reward_sat' => 'integer',
        'read_seconds' => 'integer',
        'daily_limit_per_user' => 'integer',
    ];

    public function views(): HasMany
    {
        return $this->hasMany(InternalArticleView::class);
    }
}
