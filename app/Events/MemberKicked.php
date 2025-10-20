<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MemberKicked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public int $kickedUserId;
    public int $timestamp;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $sessionId,
        int $kickedUserId,
        int $timestamp
    ) {
        $this->sessionId = $sessionId;
        $this->kickedUserId = $kickedUserId;
        $this->timestamp = $timestamp;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        // Send to kicked user's personal channel
        return new PrivateChannel('user.' . $this->kickedUserId);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'MemberKicked';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'kicked_user_id' => $this->kickedUserId,
            'timestamp' => $this->timestamp,
            'message' => 'You have been removed from the lobby',
        ];
    }
}
