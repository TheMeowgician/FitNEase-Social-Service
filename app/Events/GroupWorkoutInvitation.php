<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GroupWorkoutInvitation implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $groupId;
    public $initiatorId;
    public $initiatorName;
    public $workoutData;
    public $sessionId;

    /**
     * Create a new event instance.
     */
    public function __construct(
        int $groupId,
        int $initiatorId,
        string $initiatorName,
        array $workoutData,
        string $sessionId
    ) {
        $this->groupId = $groupId;
        $this->initiatorId = $initiatorId;
        $this->initiatorName = $initiatorName;
        $this->workoutData = $workoutData;
        $this->sessionId = $sessionId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('group.' . $this->groupId),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'group_id' => $this->groupId,
            'initiator_id' => $this->initiatorId,
            'initiator_name' => $this->initiatorName,
            'workout_data' => $this->workoutData,
            'session_id' => $this->sessionId,
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'GroupWorkoutInvitation';
    }
}
