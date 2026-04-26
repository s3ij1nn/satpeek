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
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

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

    protected $fillable = [
        'username',
        'email',
        'password',
        'faucetpay_email',
        'referrer_id',
        'referral_code',
        'registration_ip',
        // The following are admin-only — exposed via Filament UserResource.
        // The trajectory captcha + signup form do NOT mass-assign these.
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
