<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Ready Check Complete
 *
 * Broadcast when a ready check finishes (either all accepted, someone declined, or timeout).
 * All lobby members receive this event to know the result.
 */
class ReadyCheckComplete implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public string $readyCheckId;
    public bool $success;
    public string $reason; // 'all_accepted', 'declined', 'timeout'
    public array $responses;
    public int $completedAt;

    public function __construct(
        string $sessionId,
        string $readyCheckId,
        bool $success,
        string $reason,
        array $responses = []
    ) {
        $this->sessionId = $sessionId;
        $this->readyCheckId = $readyCheckId;
        $this->success = $success;
        $this->reason = $reason;
        $this->responses = $responses;
        $this->completedAt = time();
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('lobby.' . $this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'ReadyCheckComplete';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'ready_check_id' => $this->readyCheckId,
            'success' => $this->success,
            'reason' => $this->reason,
            'responses' => $this->responses,
            'completed_at' => $this->completedAt,
        ];
    }
}
