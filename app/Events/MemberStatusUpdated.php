<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MemberStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public int $userId;
    public string $oldStatus;
    public string $newStatus;
    public array $lobbyState;
    public int $version;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $sessionId,
        int $userId,
        string $oldStatus,
        string $newStatus,
        array $lobbyState,
        int $version
    ) {
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
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
        return 'member.status.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'lobby_state' => $this->lobbyState,
            'version' => $this->version,
            'timestamp' => time(),
        ];
    }
}
