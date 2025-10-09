<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PassInitiatorRole implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $sessionId;
    public $newInitiatorId;
    public $newInitiatorName;
    public $previousInitiatorId;
    public $previousInitiatorName;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $sessionId,
        int $newInitiatorId,
        string $newInitiatorName,
        int $previousInitiatorId,
        string $previousInitiatorName
    ) {
        $this->sessionId = $sessionId;
        $this->newInitiatorId = $newInitiatorId;
        $this->newInitiatorName = $newInitiatorName;
        $this->previousInitiatorId = $previousInitiatorId;
        $this->previousInitiatorName = $previousInitiatorName;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('lobby.' . $this->sessionId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'PassInitiatorRole';
    }
}
