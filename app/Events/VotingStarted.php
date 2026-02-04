<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Voting Started
 *
 * Broadcast when voting starts after exercises are generated.
 * All lobby members receive this event and can vote within the timeout.
 *
 * Voting determines whether to accept recommended exercises or customize.
 * Members vote 'accept' to use ML recommendations as-is, or 'customize' to modify.
 * Majority wins. Default is 'accept' if member doesn't vote within timeout.
 */
class VotingStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public string $votingId;
    public int $initiatorId;
    public string $initiatorName;
    public array $members;
    public array $exercises;
    public array $alternativePool;
    public int $timeoutSeconds;
    public int $expiresAt;

    public function __construct(
        string $sessionId,
        string $votingId,
        int $initiatorId,
        string $initiatorName,
        array $members,
        array $exercises,
        array $alternativePool,
        int $timeoutSeconds = 60
    ) {
        $this->sessionId = $sessionId;
        $this->votingId = $votingId;
        $this->initiatorId = $initiatorId;
        $this->initiatorName = $initiatorName;
        $this->members = $members;
        $this->exercises = $exercises;
        $this->alternativePool = $alternativePool;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->expiresAt = time() + $timeoutSeconds;
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('lobby.' . $this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'VotingStarted';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'voting_id' => $this->votingId,
            'initiator_id' => $this->initiatorId,
            'initiator_name' => $this->initiatorName,
            'members' => $this->members,
            'exercises' => $this->exercises,
            'alternative_pool' => $this->alternativePool,
            'timeout_seconds' => $this->timeoutSeconds,
            'expires_at' => $this->expiresAt,
        ];
    }
}
