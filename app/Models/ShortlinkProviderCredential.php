<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Operator-managed shortlink provider — the API credential AND the
 * per-click economics live here. Each row is one provider (btcut.io /
 * cuty.io / exe.io / shrtfly.com / ouo.io / etc).
 *
 * Shortlink earn flow uses this row in two places:
 *   1. To shorten the per-click `/shortlinks/auth/{token}` URL via
 *      this provider's API (api_base + api_token).
 *   2. To set the reward / hold / daily-limit-per-user economics on
 *      the resulting ShortlinkClick row (snapshotted at click creation).
 *
 * The api_token is encrypted with the application key (Laravel cast)
 * so leaks via DB dumps don't surface usable tokens.
 *
 * @property int $id
 * @property string $name
 * @property string|null $label
 * @property string $transport 'query' | 'path'
 * @property string $api_base
 * @property string|null $api_token encrypted at rest
 * @property bool $is_active
 * @property int $reward_sat per-click reward to viewer
 * @property int $hold_seconds return-side hold before payout
 * @property int $daily_limit_per_user
 * @property Carbon|null $last_used_at
 */
class ShortlinkProviderCredential extends Model
{
    public const TRANSPORTS = ['query', 'path'];

    protected $fillable = [
        'name',
        'label',
        'transport',
        'api_base',
        'api_token',
        'is_active',
        'reward_sat',
        'hold_seconds',
        'daily_limit_per_user',
        'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'api_token' => 'encrypted',
        'reward_sat' => 'integer',
        'hold_seconds' => 'integer',
        'daily_limit_per_user' => 'integer',
    ];
}
