<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Durable record of cron / scheduled-job events worth keeping
 * around for post-incident review. Counterpart to {@see AdminAuditLog}
 * (which records UI-driven mutations) — this one captures what the
 * SYSTEM did to itself.
 *
 * Use the static `record()` helper from anywhere — it never throws
 * even if the DB write fails (we don't want a logging failure to
 * cascade into the actual failure handler). On DB unavailability
 * the call falls through to Log::warn so the line still lands in
 * storage/logs.
 *
 * @property int $id
 * @property string $source e.g. `cron:satpeek:hot-wallet-alert`
 * @property string $level 'info' | 'warning' | 'error'
 * @property string $summary one-line description
 * @property array<string, mixed>|null $detail structured context
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SystemAuditLog extends Model
{
    public const LEVEL_INFO = 'info';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_ERROR = 'error';

    protected $fillable = [
        'source',
        'level',
        'summary',
        'detail',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'detail' => AsArrayObject::class,
    ];

    /**
     * Record an audit row. Never throws — a logging path that
     * itself fails would mask the failure it's trying to capture,
     * which is the worst possible behaviour. On DB unavailability
     * the line falls through to Log::warn.
     *
     * @param  array<string, mixed>  $detail
     */
    public static function record(string $source, string $level, string $summary, array $detail = []): void
    {
        try {
            self::create([
                'source' => $source,
                'level' => $level,
                'summary' => substr($summary, 0, 500),
                'detail' => $detail,
                'occurred_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SystemAuditLog write failed; falling back to log channel', [
                'source' => $source,
                'level' => $level,
                'summary' => $summary,
                'detail' => $detail,
                'audit_err' => $e->getMessage(),
            ]);
        }
    }
}
