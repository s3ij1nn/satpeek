<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsEncryptedString;
use Illuminate\Database\Eloquent\Model;

/**
 * Operator-managed shortener credential row. The api_token is encrypted
 * with the application key (Laravel cast) so leaks via DB dumps don't
 * surface usable tokens.
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
        'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'api_token' => 'encrypted',
    ];
}
