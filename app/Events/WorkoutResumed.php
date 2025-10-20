<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkoutResumed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $sessionId;
    public $resumedBy;
    public $resumedByName;
    public $resumedAt;
    public $sessionState;

    /**
     * Create a new event instance.
     *
     * @param string $sessionId Session ID
     * @param int $resumedBy User ID who resumed
     * @param string $resumedByName Username who resumed
     * @param int $resumedAt Unix timestamp
     * @param array $sessionState Exact workout state for synchronization
     */
    public function __construct(string $sessionId, int $resumedBy, string $resumedByName, int $resumedAt, array $sessionState = [])
    {
        $this->sessionId = $sessionId;
        $this->resumedBy = $resumedBy;
        $this->resumedByName = $resumedByName;
        $this->resumedAt = $resumedAt;
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
        return 'WorkoutResumed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'resumed_by' => $this->resumedBy,
            'resumed_by_name' => $this->resumedByName,
            'resumed_at' => $this->resumedAt,
            'session_state' => $this->sessionState,
        ];
    }
}
