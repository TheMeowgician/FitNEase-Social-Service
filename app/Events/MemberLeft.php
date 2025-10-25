<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MemberLeft implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public int $userId;
    public string $userName;
    public array $lobbyState;
    public int $version;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $sessionId,
        int $userId,
        string $userName,
        array $lobbyState,
        int $version
    ) {
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->userName = $userName;
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
        return 'member.left';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'lobby_state' => $this->lobbyState,
            'version' => $this->version,
            'timestamp' => time(),
        ];
    }
}
