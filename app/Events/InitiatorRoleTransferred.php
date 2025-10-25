<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InitiatorRoleTransferred implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public int $oldInitiatorId;
    public int $newInitiatorId;
    public string $newInitiatorName;
    public array $lobbyState;
    public int $version;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $sessionId,
        int $oldInitiatorId,
        int $newInitiatorId,
        string $newInitiatorName,
        array $lobbyState,
        int $version
    ) {
        $this->sessionId = $sessionId;
        $this->oldInitiatorId = $oldInitiatorId;
        $this->newInitiatorId = $newInitiatorId;
        $this->newInitiatorName = $newInitiatorName;
        $this->lobbyState = $lobbyState;
        $this->version = $version;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        return new PrivateChannel('lobby.' . $this->sessionId);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'initiator.transferred';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'old_initiator_id' => $this->oldInitiatorId,
            'new_initiator_id' => $this->newInitiatorId,
            'new_initiator_name' => $this->newInitiatorName,
            'lobby_state' => $this->lobbyState,
            'version' => $this->version,
            'timestamp' => time(),
        ];
    }
}
