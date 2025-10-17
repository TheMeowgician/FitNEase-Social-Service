<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class WorkoutInvitation extends Model
{
    protected $fillable = [
        'invitation_id',
        'session_id',
        'group_id',
        'initiator_id',
        'invited_user_id',
        'workout_data',
        'status',
        'sent_at',
        'expires_at',
        'responded_at',
        'response_reason',
    ];

    protected $casts = [
        'workout_data' => 'array',
        'sent_at' => 'datetime',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    // ==================== SCOPES ====================

    /**
     * Scope: Pending invitations
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending')
                     ->where('expires_at', '>', now());
    }

    /**
     * Scope: Expired invitations
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'pending')
                     ->where('expires_at', '<=', now());
    }

    /**
     * Scope: For specific user
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('invited_user_id', $userId);
    }

    /**
     * Scope: For specific session
     */
    public function scopeForSession(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId);
    }

    // ==================== ACCESSORS ====================

    /**
     * Check if invitation is expired
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if invitation is pending
     */
    public function getIsPendingAttribute(): bool
    {
        return $this->status === 'pending' && !$this->is_expired;
    }

    /**
     * Get time remaining in seconds
     */
    public function getTimeRemainingAttribute(): int
    {
        if ($this->is_expired) {
            return 0;
        }
        return max(0, $this->expires_at->diffInSeconds(now()));
    }

    // ==================== BUSINESS LOGIC ====================

    /**
     * Accept the invitation
     */
    public function accept(): bool
    {
        if (!$this->is_pending) {
            return false;
        }

        return $this->update([
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    /**
     * Decline the invitation
     */
    public function decline(string $reason = null): bool
    {
        if (!$this->is_pending) {
            return false;
        }

        return $this->update([
            'status' => 'declined',
            'responded_at' => now(),
            'response_reason' => $reason,
        ]);
    }

    /**
     * Cancel the invitation (by initiator)
     */
    public function cancel(string $reason = null): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        return $this->update([
            'status' => 'cancelled',
            'response_reason' => $reason,
        ]);
    }

    /**
     * Mark as expired (background job)
     */
    public function markAsExpired(): bool
    {
        return $this->update(['status' => 'expired']);
    }
}
