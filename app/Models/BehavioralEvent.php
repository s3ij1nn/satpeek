<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BehavioralEvent extends Model
{
    protected $fillable = [
        'user_id',
        'session_id',
        'kind',
        'payload',
        'client_ip',
        'observed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'observed_at' => 'datetime',
    ];
}
