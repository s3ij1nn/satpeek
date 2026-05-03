<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * Minimal PHPDoc — only the columns + relations app code reaches via Eloquent
 * magic accessors that Larastan can't statically resolve. Full schema lives
 * in the migrations.
 *
 * @property int $id
 * @property string $username
 * @property string $email
 * @property int $balance_sat
 * @property int $total_earned_sat
 * @property int $total_withdrawn_sat
 * @property int|null $referrer_id
 * @property string $referral_code
 * @property bool $is_admin
 * @property bool $is_banned
 * @property string|null $ban_reason
 * @property string|null $adblock_status `clean` | `detected` | null=unchecked
 * @property Carbon|null $adblock_checked_at
 * @property-read BotScore|null $botScore
 */
class User extends Authenticatable implements FilamentUser, HasName, MustVerifyEmail
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    /** Filament: display name in the top-right user-menu / avatar. */
    public function getFilamentName(): string
    {
        return $this->username ?? $this->email ?? 'user';
    }

    /** Filament: gate panel access to admin accounts only. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() !== 'admin' || (bool) $this->is_admin;
    }

    /**
     * Includes admin-only fields (`balance_sat`, `is_admin`, `is_banned`,
     * `ban_reason`) so the Filament UserResource form can save them
     * via the framework's standard `fill()` path. Non-admin code MUST
     * NOT mass-assign these from request input — every controller in
     * `app/Http/Controllers` builds the create/update payload from
     * validated explicit fields, never from `$request->all()`. A grep
     * for `$request->all()` is intentionally zero hits across `app/`.
     * If you add a new code path that mass-assigns from a request,
     * either swap to an explicit field map or guard the admin-only
     * fields with `$guarded = [...]` instead.
     */
    protected $fillable = [
        'username',
        'email',
        'password',
        'faucetpay_email',
        'referrer_id',
        'referral_code',
        'registration_ip',
        // Admin-only — see class docblock above.
        'balance_sat',
        'is_admin',
        'is_banned',
        'ban_reason',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_banned' => 'boolean',
            'adblock_checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->referral_code)) {
                $user->referral_code = static::generateReferralCode();
            }
        });
    }

    public static function generateReferralCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (static::where('referral_code', $code)->exists());

        return $code;
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referrer_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referrer_id');
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(BalanceLedger::class);
    }

    public function botScore(): HasOne
    {
        return $this->hasOne(BotScore::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function ptcViews(): HasMany
    {
        return $this->hasMany(PtcView::class);
    }
}
