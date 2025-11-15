<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgoraParticipant extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'user_id',
        'token',
        'role',
        'video_enabled',
        'audio_enabled',
        'joined_at',
        'left_at',
    ];

    protected $casts = [
        'video_enabled' => 'boolean',
        'audio_enabled' => 'boolean',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];
}
