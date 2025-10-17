<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkoutCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $sessionId;
    public $initiatorId;
    public $initiatorName;

    /**
     * Create a new event instance.
     */
    public function __construct($sessionId, $initiatorId, $initiatorName)
    {
        $this->sessionId = $sessionId;
        $this->initiatorId = $initiatorId;
        $this->initiatorName = $initiatorName;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        return new PrivateChannel('session.' . $this->sessionId);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'WorkoutCompleted';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'initiatorId' => $this->initiatorId,
            'initiatorName' => $this->initiatorName,
            'timestamp' => now()->timestamp,
        ];
    }
}
