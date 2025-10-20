<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkoutPaused implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $sessionId;
    public $pausedBy;
    public $pausedByName;
    public $pausedAt;
    public $sessionState;

    /**
     * Create a new event instance.
     *
     * @param string $sessionId Session ID
     * @param int $pausedBy User ID who paused
     * @param string $pausedByName Username who paused
     * @param int $pausedAt Unix timestamp
     * @param array $sessionState Exact workout state (time_remaining, phase, etc.) for synchronization
     */
    public function __construct(string $sessionId, int $pausedBy, string $pausedByName, int $pausedAt, array $sessionState = [])
    {
        $this->sessionId = $sessionId;
        $this->pausedBy = $pausedBy;
        $this->pausedByName = $pausedByName;
        $this->pausedAt = $pausedAt;
        $this->sessionState = $sessionState;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('session.' . $this->sessionId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'WorkoutPaused';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'paused_by' => $this->pausedBy,
            'paused_by_name' => $this->pausedByName,
            'paused_at' => $this->pausedAt,
            'session_state' => $this->sessionState, // Include exact state for perfect sync
        ];
    }
}
