<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkoutLobbyChatMessage extends Model
{
    protected $fillable = [
        'message_id',
        'lobby_id',
        'user_id',
        'message',
        'is_system_message',
    ];

    protected $casts = [
        'is_system_message' => 'boolean',
    ];

    /**
     * Relationships
     */
    public function lobby(): BelongsTo
    {
        return $this->belongsTo(WorkoutLobby::class, 'lobby_id');
    }
}
