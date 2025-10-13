<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use App\Events\LobbyDeleted;

class WorkoutLobby extends Model
{
    protected $fillable = [
        'session_id',
        'group_id',
        'initiator_id',
        'workout_data',
        'status',
        'started_at',
        'expires_at',
    ];

    protected $casts = [
        'workout_data' => 'array',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Relationships
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id', 'group_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(WorkoutLobbyMember::class, 'lobby_id');
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(WorkoutLobbyChatMessage::class, 'lobby_id');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }

    /**
     * Methods
     */
    public function addMember(int $userId, string $status = 'waiting'): WorkoutLobbyMember
    {
        return $this->members()->create([
            'user_id' => $userId,
            'status' => $status,
            'is_active' => true,
        ]);
    }

    public function removeMember(int $userId): bool
    {
        $member = $this->members()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->first();

        if ($member) {
            $member->update([
                'is_active' => false,
                'left_at' => now(),
            ]);
            return true;
        }

        return false;
    }

    public function updateMemberStatus(int $userId, string $status): bool
    {
        $member = $this->members()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->first();

        if ($member) {
            $member->update(['status' => $status]);
            return true;
        }

        return false;
    }

    public function getActiveMembers()
    {
        return $this->members()->active()->get();
    }

    public function updateWorkoutData(array $workoutData): bool
    {
        return $this->update(['workout_data' => $workoutData]);
    }

    public function markAsStarted(): bool
    {
        return $this->update([
            'status' => 'started',
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(): bool
    {
        return $this->update(['status' => 'completed']);
    }

    public function markAsCancelled(): bool
    {
        return $this->update(['status' => 'cancelled']);
    }

    public function deleteIfEmpty(): bool
    {
        $activeMembersCount = $this->members()->active()->count();

        if ($activeMembersCount === 0) {
            Log::info('Lobby is empty, deleting', [
                'session_id' => $this->session_id,
                'lobby_id' => $this->id
            ]);

            // Broadcast LobbyDeleted event before deleting
            broadcast(new LobbyDeleted($this->session_id));

            // Delete lobby (cascade will delete members and messages)
            $this->delete();

            return true;
        }

        return false;
    }

    public function addSystemMessage(string $message): WorkoutLobbyChatMessage
    {
        $messageId = \Illuminate\Support\Str::uuid()->toString();
        $timestamp = time();

        $chatMessage = $this->chatMessages()->create([
            'message_id' => $messageId,
            'user_id' => 0, // System user
            'message' => $message,
            'is_system_message' => true,
        ]);

        // Broadcast system message to all lobby members
        broadcast(new \App\Events\LobbyMessageSent(
            $this->session_id,
            0, // System user ID
            'System',
            $message,
            $timestamp,
            $messageId,
            true // is_system_message
        ));

        return $chatMessage;
    }
}
