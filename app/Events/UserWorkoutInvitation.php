<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserWorkoutInvitation implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $invitationId;
    public string $sessionId;
    public int $groupId;
    public int $initiatorId;
    public string $initiatorName;
    public int $invitedUserId;
    public array $workoutData;
    public int $expiresAt; // Unix timestamp

    public function __construct(
        string $invitationId,
        string $sessionId,
        int $groupId,
        int $initiatorId,
        string $initiatorName,
        int $invitedUserId,
        array $workoutData,
        int $expiresAt
    ) {
        $this->invitationId = $invitationId;
        $this->sessionId = $sessionId;
        $this->groupId = $groupId;
        $this->initiatorId = $initiatorId;
        $this->initiatorName = $initiatorName;
        $this->invitedUserId = $invitedUserId;
        $this->workoutData = $workoutData;
        $this->expiresAt = $expiresAt;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        // User-specific private channel - ONLY invited user receives it
        return new PrivateChannel('user.' . $this->invitedUserId);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'UserWorkoutInvitation';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'invitation_id' => $this->invitationId,
            'session_id' => $this->sessionId,
            'group_id' => $this->groupId,
            'initiator_id' => $this->initiatorId,
            'initiator_name' => $this->initiatorName,
            'invited_user_id' => $this->invitedUserId,
            'workout_data' => $this->workoutData,
            'expires_at' => $this->expiresAt,
        ];
    }
}
