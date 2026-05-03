<?php

namespace App\Models;

use App\Services\AdminAuditor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only operator action log row. See migration docblock for the
 * what / why. Always written via {@see AdminAuditor} so
 * payload normalisation and admin/IP attribution stay in one place.
 *
 * @property int $id
 * @property int|null $admin_user_id
 * @property string $action
 * @property string|null $target_type
 * @property int|null $target_id
 * @property array<string, mixed>|null $payload
 * @property string|null $client_ip
 * @property Carbon $created_at
 * @property-read User|null $admin
 */
class AdminAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'admin_audit_log';

    protected $fillable = [
        'admin_user_id',
        'action',
        'target_type',
        'target_id',
        'payload',
        'client_ip',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
