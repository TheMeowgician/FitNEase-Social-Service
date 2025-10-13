<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkoutLobbyMember extends Model
{
    protected $fillable = [
        'lobby_id',
        'user_id',
        'status',
        'joined_at',
        'left_at',
        'is_active',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Relationships
     */
    public function lobby(): BelongsTo
    {
        return $this->belongsTo(WorkoutLobby::class, 'lobby_id');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeReady($query)
    {
        return $query->where('status', 'ready');
    }
}
