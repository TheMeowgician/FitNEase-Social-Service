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

    /**
     * Create a new event instance.
     */
    public function __construct(string $sessionId, int $pausedBy, string $pausedByName, int $pausedAt)
    {
        $this->sessionId = $sessionId;
        $this->pausedBy = $pausedBy;
        $this->pausedByName = $pausedByName;
        $this->pausedAt = $pausedAt;
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
        ];
    }
}
