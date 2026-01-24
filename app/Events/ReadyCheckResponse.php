<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Ready Check Response
 *
 * Broadcast when a member responds to a ready check (accept or decline).
 * All lobby members receive this event to update their UI.
 */
class ReadyCheckResponse implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public string $readyCheckId;
    public int $userId;
    public string $userName;
    public string $response; // 'accepted' or 'declined'
    public int $respondedAt;

    public function __construct(
        string $sessionId,
        string $readyCheckId,
        int $userId,
        string $userName,
        string $response
    ) {
        $this->sessionId = $sessionId;
        $this->readyCheckId = $readyCheckId;
        $this->userId = $userId;
        $this->userName = $userName;
        $this->response = $response;
        $this->respondedAt = time();
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('lobby.' . $this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'ReadyCheckResponse';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'ready_check_id' => $this->readyCheckId,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'response' => $this->response,
            'responded_at' => $this->respondedAt,
        ];
    }
}
