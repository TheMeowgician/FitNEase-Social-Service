<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

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

    // ==================== RELATIONSHIPS ====================

    /**
     * Message belongs to a lobby
     */
    public function lobby(): BelongsTo
    {
        return $this->belongsTo(WorkoutLobby::class, 'lobby_id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope: Only user messages (not system)
     */
    public function scopeUserMessages(Builder $query): Builder
    {
        return $query->where('is_system_message', false);
    }

    /**
     * Scope: Only system messages
     */
    public function scopeSystemMessages(Builder $query): Builder
    {
        return $query->where('is_system_message', true);
    }

    /**
     * Scope: Recent messages (last N)
     */
    public function scopeRecent(Builder $query, int $limit = 50): Builder
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }

    // ==================== ACCESSORS ====================

    /**
     * Get formatted timestamp
     */
    public function getTimestampAttribute(): int
    {
        return $this->created_at->timestamp;
    }
}
