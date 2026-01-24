<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Ready Check Cancelled
 *
 * Broadcast when the initiator cancels an active ready check.
 * All lobby members receive this event to dismiss their ready check modal.
 */
class ReadyCheckCancelled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public string $readyCheckId;
    public int $cancelledBy;
    public string $reason;
    public int $cancelledAt;

    public function __construct(
        string $sessionId,
        string $readyCheckId,
        int $cancelledBy,
        string $reason = 'initiator_cancelled'
    ) {
        $this->sessionId = $sessionId;
        $this->readyCheckId = $readyCheckId;
        $this->cancelledBy = $cancelledBy;
        $this->reason = $reason;
        $this->cancelledAt = time();
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('lobby.' . $this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'ReadyCheckCancelled';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'ready_check_id' => $this->readyCheckId,
            'cancelled_by' => $this->cancelledBy,
            'reason' => $this->reason,
            'cancelled_at' => $this->cancelledAt,
        ];
    }
}
