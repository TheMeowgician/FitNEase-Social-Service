<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkoutStopped implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $sessionId;
    public $stoppedBy;
    public $stoppedByName;
    public $stoppedAt;

    /**
     * Create a new event instance.
     */
    public function __construct(string $sessionId, int $stoppedBy, string $stoppedByName, int $stoppedAt)
    {
        $this->sessionId = $sessionId;
        $this->stoppedBy = $stoppedBy;
        $this->stoppedByName = $stoppedByName;
        $this->stoppedAt = $stoppedAt;
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
        return 'WorkoutStopped';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'stopped_by' => $this->stoppedBy,
            'stopped_by_name' => $this->stoppedByName,
            'stopped_at' => $this->stoppedAt,
        ];
    }
}
