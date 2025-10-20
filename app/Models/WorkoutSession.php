<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Server-authoritative workout session
 *
 * The server is the single source of truth for workout state.
 * Clients listen to server broadcasts for timer updates.
 */
class WorkoutSession extends Model
{
    protected $fillable = [
        'session_id',
        'lobby_id',
        'initiator_id',
        'status',
        'time_remaining',
        'phase',
        'current_exercise',
        'current_set',
        'current_round',
        'calories_burned',
        'started_at',
        'paused_at',
        'resumed_at',
        'completed_at',
        'total_pause_duration',
        'workout_data',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'resumed_at' => 'datetime',
        'completed_at' => 'datetime',
        'workout_data' => 'array',
        'calories_burned' => 'decimal:2',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Session belongs to a lobby
     */
    public function lobby(): BelongsTo
    {
        return $this->belongsTo(WorkoutLobby::class, 'lobby_id');
    }

    // ==================== BUSINESS LOGIC ====================

    /**
     * Check if session is running
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if session is paused
     */
    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    /**
     * Pause the session
     */
    public function pause(): void
    {
        $this->update([
            'status' => 'paused',
            'paused_at' => now(),
        ]);
    }

    /**
     * Resume the session
     */
    public function resume(): void
    {
        if ($this->paused_at) {
            // Calculate pause duration
            $pauseDuration = now()->diffInSeconds($this->paused_at);
            $this->increment('total_pause_duration', $pauseDuration);
        }

        $this->update([
            'status' => 'running',
            'resumed_at' => now(),
            'paused_at' => null,
        ]);
    }

    /**
     * Stop the session
     */
    public function stop(): void
    {
        $this->update([
            'status' => 'stopped',
            'completed_at' => now(),
        ]);
    }

    /**
     * Complete the session
     */
    public function complete(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'phase' => 'complete',
            'time_remaining' => 0,
        ]);
    }

    /**
     * Decrement timer (called every second by server)
     */
    public function tick(): bool
    {
        if (!$this->isRunning()) {
            return false;
        }

        if ($this->time_remaining <= 0) {
            return false;
        }

        $this->decrement('time_remaining');
        return true;
    }

    /**
     * Get current state as array
     */
    public function getCurrentState(): array
    {
        return [
            'session_id' => $this->session_id,
            'status' => $this->status,
            'time_remaining' => $this->time_remaining,
            'phase' => $this->phase,
            'current_exercise' => $this->current_exercise,
            'current_set' => $this->current_set,
            'current_round' => $this->current_round,
            'calories_burned' => (float) $this->calories_burned,
        ];
    }
}
