<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operator-managed override for a single bot-detection signal's weight
 * + enabled flag. See migration docblock for rationale.
 *
 * @property int $id
 * @property string $name signal key, e.g. "shared_ip"
 * @property float $weight 0.000–1.000
 * @property bool $is_enabled
 * @property string|null $notes
 */
class BotSignalWeight extends Model
{
    protected $fillable = [
        'name',
        'weight',
        'is_enabled',
        'notes',
    ];

    protected $casts = [
        'weight' => 'float',
        'is_enabled' => 'boolean',
    ];
}
