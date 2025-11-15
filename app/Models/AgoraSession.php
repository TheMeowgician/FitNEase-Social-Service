<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgoraSession extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'channel_name',
        'created_at',
        'expires_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
