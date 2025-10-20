<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Server-Authoritative Timer Tick
 *
 * Broadcasted every second to all clients in a session
 * Clients display this time (server is single source of truth)
 */
class WorkoutSessionTick implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $sessionState;

    /**
     * Create a new event instance.
     *
     * @param array $sessionState Current workout state from server
     */
    public function __construct(array $sessionState)
    {
        $this->sessionState = $sessionState;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('session.' . $this->sessionState['session_id']),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'SessionTick';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return $this->sessionState;
    }
}
