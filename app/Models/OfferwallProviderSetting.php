<?php

declare(strict_types=1);

namespace App\Models;

use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Operator-managed enable/disable flag for an offerwall publisher
 * integration. Resolved at request time by
 * {@see AppServiceProvider} — DB rows take precedence
 * over the env-driven `OFFERWALLS_ENABLED` list, so an admin can flip
 * `bitcotask` on the moment the publisher review approves API access
 * without a redeploy.
 *
 * Intentionally NOT a credential store. API keys / bearer tokens / S2S
 * secrets stay in env / config so the secret-leak surface stays the
 * same as today.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_enabled
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OfferwallProviderSetting extends Model
{
    protected $fillable = [
        'name',
        'is_enabled',
        'notes',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];
}
