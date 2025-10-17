<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MemberJoined implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public array $member;
    public array $lobbyState;
    public int $version;

    /**
     * Create a new event instance.
     */
    public function __construct(string $sessionId, array $member, array $lobbyState, int $version)
    {
        $this->sessionId = $sessionId;
        $this->member = $member;
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
        return 'member.joined';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'member' => $this->member,
            'lobby_state' => $this->lobbyState,
            'version' => $this->version,
            'timestamp' => time(),
        ];
    }
}
