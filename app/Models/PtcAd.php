<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PtcAd extends Model
{
    public const STATUSES = ['draft', 'pending_review', 'approved', 'paused', 'completed', 'rejected'];

    public const DISPLAY_MODES = ['iframe', 'window'];

    protected $fillable = [
        'user_id',
        'source',
        'external_id',
        'title',
        'description',
        'target_url',
        'display_mode',
        'reward_sat',
        'cost_per_view_sat',
        'total_views_purchased',
        'views_remaining',
        'total_cost_sat',
        'duration_sec',
        'daily_limit_per_user',
        'is_active',
        'expires_at',
        'meta',
        'status',
        'submission_notes',
        'rejection_reason',
        'approved_at',
        'reviewed_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'approved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function advertiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Whether this ad is currently being served to viewers.
     * Admin-created rows (user_id = null) ignore views_remaining since they
     * don't carry a budget — `is_active` alone gates them.
     */
    public function isServable(): bool
    {
        if (! $this->is_active || $this->status !== 'approved') {
            return false;
        }
        if ($this->user_id !== null && (int) $this->views_remaining <= 0) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }
        return true;
    }
}
