<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Vote Submitted
 *
 * Broadcast when a member submits their vote (accept or customize).
 * All lobby members receive this event to update their UI with vote counts.
 *
 * Vote options:
 * - 'accept': Use ML recommended exercises as-is
 * - 'customize': Member wants to modify the workout
 */
class VoteSubmitted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public string $votingId;
    public int $userId;
    public string $userName;
    public string $vote; // 'accept' or 'customize'
    public int $votedAt;
    public array $currentVotes; // Current vote counts for real-time display

    public function __construct(
        string $sessionId,
        string $votingId,
        int $userId,
        string $userName,
        string $vote,
        array $currentVotes = []
    ) {
        $this->sessionId = $sessionId;
        $this->votingId = $votingId;
        $this->userId = $userId;
        $this->userName = $userName;
        $this->vote = $vote;
        $this->votedAt = time();
        $this->currentVotes = $currentVotes;
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('lobby.' . $this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'VoteSubmitted';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'voting_id' => $this->votingId,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'vote' => $this->vote,
            'voted_at' => $this->votedAt,
            'current_votes' => $this->currentVotes,
        ];
    }
}
