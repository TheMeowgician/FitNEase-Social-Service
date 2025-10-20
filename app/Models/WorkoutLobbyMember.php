<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class WorkoutLobbyMember extends Model
{
    protected $fillable = [
        'lobby_id',
        'user_id',
        'user_name',
        'status',
        'joined_at',
        'left_at',
        'left_reason',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Member belongs to a lobby
     */
    public function lobby(): BelongsTo
    {
        return $this->belongsTo(WorkoutLobby::class, 'lobby_id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope: Only active members
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['waiting', 'ready']);
    }

    /**
     * Scope: Only ready members
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', 'ready');
    }

    /**
     * Scope: By user
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    // ==================== BUSINESS LOGIC ====================

    /**
     * Mark member as ready
     */
    public function markAsReady(): bool
    {
        return $this->update(['status' => 'ready']);
    }

    /**
     * Mark member as waiting
     */
    public function markAsWaiting(): bool
    {
        return $this->update(['status' => 'waiting']);
    }

    /**
     * Mark member as left
     */
    public function markAsLeft(string $reason = 'user_left'): bool
    {
        return $this->update([
            'status' => 'left',
            'left_at' => now(),
            'left_reason' => $reason,
        ]);
    }

    /**
     * Mark member as kicked
     */
    public function markAsKicked(): bool
    {
        return $this->update([
            'status' => 'kicked',
            'left_at' => now(),
            'left_reason' => 'kicked',
        ]);
    }

    /**
     * Check if member is active
     */
    public function isActive(): bool
    {
        return in_array($this->status, ['waiting', 'ready']);
    }
}
