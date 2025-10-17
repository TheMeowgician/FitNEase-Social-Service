<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkoutDataUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public array $workoutData;
    public array $lobbyState;
    public int $version;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $sessionId,
        array $workoutData,
        array $lobbyState,
        int $version
    ) {
        $this->sessionId = $sessionId;
        $this->workoutData = $workoutData;
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
        return 'workout.data.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'workout_data' => $this->workoutData,
            'lobby_state' => $this->lobbyState,
            'version' => $this->version,
            'timestamp' => time(),
        ];
    }
}
