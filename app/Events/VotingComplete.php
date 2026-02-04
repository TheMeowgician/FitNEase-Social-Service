<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Voting Complete
 *
 * Broadcast when voting finishes (either all voted or timeout).
 * All lobby members receive this event to know the result.
 *
 * Result is determined by majority vote.
 * If a member doesn't vote within timeout, their vote defaults to 'accept'.
 *
 * Results:
 * - 'accept_recommended': Majority voted to accept ML recommendations
 * - 'customize': Majority voted to customize (triggers per-exercise voting if implemented)
 */
class VotingComplete implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public string $votingId;
    public string $result; // 'accept_recommended' or 'customize'
    public string $reason; // 'all_voted', 'majority', 'timeout'
    public array $finalVotes; // All votes including defaults for non-voters
    public int $acceptCount;
    public int $customizeCount;
    public array $finalExercises; // The exercises to use for the workout
    public int $completedAt;

    public function __construct(
        string $sessionId,
        string $votingId,
        string $result,
        string $reason,
        array $finalVotes,
        int $acceptCount,
        int $customizeCount,
        array $finalExercises = []
    ) {
        $this->sessionId = $sessionId;
        $this->votingId = $votingId;
        $this->result = $result;
        $this->reason = $reason;
        $this->finalVotes = $finalVotes;
        $this->acceptCount = $acceptCount;
        $this->customizeCount = $customizeCount;
        $this->finalExercises = $finalExercises;
        $this->completedAt = time();
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('lobby.' . $this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'VotingComplete';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'voting_id' => $this->votingId,
            'result' => $this->result,
            'reason' => $this->reason,
            'final_votes' => $this->finalVotes,
            'accept_count' => $this->acceptCount,
            'customize_count' => $this->customizeCount,
            'final_exercises' => $this->finalExercises,
            'completed_at' => $this->completedAt,
        ];
    }
}
