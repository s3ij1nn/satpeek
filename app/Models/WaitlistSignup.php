<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaitlistSignup extends Model
{
    protected $fillable = [
        'email',
        'faucetpay_email',
        'referral_code',
        'source',
        'client_ip',
        'user_agent',
    ];
}
