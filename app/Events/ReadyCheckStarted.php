<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Ready Check Started
 *
 * Broadcast when the initiator starts a ready check.
 * All lobby members receive this event and must respond within the timeout.
 */
class ReadyCheckStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public int $initiatorId;
    public string $initiatorName;
    public array $members;
    public int $timeoutSeconds;
    public int $expiresAt;
    public string $readyCheckId;

    public function __construct(
        string $sessionId,
        int $initiatorId,
        string $initiatorName,
        array $members,
        int $timeoutSeconds,
        string $readyCheckId
    ) {
        $this->sessionId = $sessionId;
        $this->initiatorId = $initiatorId;
        $this->initiatorName = $initiatorName;
        $this->members = $members;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->expiresAt = time() + $timeoutSeconds;
        $this->readyCheckId = $readyCheckId;
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('lobby.' . $this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'ReadyCheckStarted';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'ready_check_id' => $this->readyCheckId,
            'initiator_id' => $this->initiatorId,
            'initiator_name' => $this->initiatorName,
            'members' => $this->members,
            'timeout_seconds' => $this->timeoutSeconds,
            'expires_at' => $this->expiresAt,
        ];
    }
}
