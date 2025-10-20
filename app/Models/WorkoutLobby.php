<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class WorkoutLobby extends Model
{
    protected $fillable = [
        'session_id',
        'group_id',
        'initiator_id',
        'workout_data',
        'status',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    protected $casts = [
        'workout_data' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Lobby has many members
     */
    public function members(): HasMany
    {
        return $this->hasMany(WorkoutLobbyMember::class, 'lobby_id');
    }

    /**
     * Get only active members (not left or kicked)
     */
    public function activeMembers(): HasMany
    {
        return $this->members()->whereIn('status', ['waiting', 'ready']);
    }

    /**
     * Lobby has many chat messages
     */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(WorkoutLobbyChatMessage::class, 'lobby_id');
    }

    /**
     * Lobby has many invitations (via session_id)
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkoutInvitation::class, 'session_id', 'session_id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope: Only active lobbies (not completed, cancelled, or expired)
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['completed', 'cancelled'])
                    ->where('expires_at', '>', now());
    }

    /**
     * Scope: Only waiting lobbies
     */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', 'waiting');
    }

    /**
     * Scope: Expired lobbies
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<', now());
    }

    /**
     * Scope: By group
     */
    public function scopeForGroup(Builder $query, int $groupId): Builder
    {
        return $query->where('group_id', $groupId);
    }

    // ==================== ACCESSORS ====================

    /**
     * Check if lobby is expired
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if lobby is active (can join)
     */
    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'waiting' && !$this->is_expired;
    }

    /**
     * Get active member count
     */
    public function getActiveMemberCountAttribute(): int
    {
        return $this->activeMembers()->count();
    }

    /**
     * Check if all members are ready
     */
    public function getAreAllMembersReadyAttribute(): bool
    {
        $members = $this->activeMembers;
        return $members->count() > 0 && $members->where('status', 'ready')->count() === $members->count();
    }

    // ==================== BUSINESS LOGIC METHODS ====================

    /**
     * Check if user is a member of this lobby
     */
    public function hasMember(int $userId): bool
    {
        return $this->activeMembers()->where('user_id', $userId)->exists();
    }

    /**
     * Check if user is the initiator
     */
    public function isInitiator(int $userId): bool
    {
        return $this->initiator_id === $userId;
    }

    /**
     * Add a member to the lobby
     * If member already exists (even if they left), rejoin them instead of creating duplicate
     *
     * @param int $userId User ID to add
     * @param string $status Initial status (waiting/ready)
     * @param string|null $userName Username to cache (for instant pause/resume broadcasts)
     */
    public function addMember(int $userId, string $status = 'waiting', ?string $userName = null): WorkoutLobbyMember
    {
        // Check if member already exists (including left/kicked members)
        $existingMember = $this->members()->where('user_id', $userId)->first();

        if ($existingMember) {
            // Rejoin: update existing record
            $updateData = [
                'status' => $status,
                'joined_at' => now(),
                'left_at' => null,
                'left_reason' => null,
            ];

            // Update username if provided (allows updating stale usernames)
            if ($userName !== null) {
                $updateData['user_name'] = $userName;
            }

            $existingMember->update($updateData);
            return $existingMember->fresh();
        }

        // New member: create new record
        return $this->members()->create([
            'user_id' => $userId,
            'user_name' => $userName, // Cache username for instant pause/resume broadcasts
            'status' => $status,
            'joined_at' => now(),
        ]);
    }

    /**
     * Remove a member from the lobby
     */
    public function removeMember(int $userId, string $reason = 'user_left'): bool
    {
        return $this->members()
            ->where('user_id', $userId)
            ->whereIn('status', ['waiting', 'ready'])
            ->update([
                'status' => $reason === 'kicked' ? 'kicked' : 'left',
                'left_at' => now(),
                'left_reason' => $reason,
            ]) > 0;
    }

    /**
     * Update member status (waiting/ready)
     */
    public function updateMemberStatus(int $userId, string $status): bool
    {
        return $this->members()
            ->where('user_id', $userId)
            ->update(['status' => $status]) > 0;
    }

    /**
     * Transfer initiator role to another member
     */
    public function transferInitiator(int $newInitiatorId): bool
    {
        // Verify new initiator is a member
        if (!$this->hasMember($newInitiatorId)) {
            return false;
        }

        $this->update(['initiator_id' => $newInitiatorId]);
        return true;
    }

    /**
     * Mark lobby as started
     */
    public function markAsStarted(): bool
    {
        return $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    /**
     * Mark lobby as completed
     */
    public function markAsCompleted(): bool
    {
        return $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark lobby as cancelled
     */
    public function markAsCancelled(): bool
    {
        return $this->update(['status' => 'cancelled']);
    }

    /**
     * Check if lobby should auto-complete (no members left)
     */
    public function shouldAutoComplete(): bool
    {
        return $this->active_member_count === 0 && $this->status === 'waiting';
    }

    /**
     * Auto-complete lobby if empty
     */
    public function autoCompleteIfEmpty(): bool
    {
        if ($this->shouldAutoComplete()) {
            return $this->markAsCompleted();
        }
        return false;
    }

    /**
     * Add system chat message
     */
    public function addSystemMessage(string $message): WorkoutLobbyChatMessage
    {
        return $this->chatMessages()->create([
            'message_id' => \Illuminate\Support\Str::uuid(),
            'user_id' => null,
            'message' => $message,
            'is_system_message' => true,
        ]);
    }
}
