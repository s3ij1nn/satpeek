<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per operator-blocked IP / CIDR.
 *
 * @property int $id
 * @property string $cidr
 * @property string|null $note
 * @property int|null $created_by_admin_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $createdByAdmin
 */
class IpBlockEntry extends Model
{
    protected $fillable = [
        'cidr',
        'note',
        'created_by_admin_id',
    ];

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }
}
