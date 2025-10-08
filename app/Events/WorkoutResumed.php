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

    /**
     * Create a new event instance.
     */
    public function __construct(string $sessionId, int $resumedBy, string $resumedByName, int $resumedAt)
    {
        $this->sessionId = $sessionId;
        $this->resumedBy = $resumedBy;
        $this->resumedByName = $resumedByName;
        $this->resumedAt = $resumedAt;
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
        ];
    }
}
