<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Exercises Reordered
 *
 * Broadcast when the customizer reorders exercises during group customization.
 * All lobby members receive this event to update their exercise list in real-time.
 */
class ExercisesReordered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public array $updatedExercises;
    public int $reorderedBy;
    public string $reorderedByName;
    public int $reorderedAt;

    public function __construct(
        string $sessionId,
        array $updatedExercises,
        int $reorderedBy,
        string $reorderedByName
    ) {
        $this->sessionId = $sessionId;
        $this->updatedExercises = $updatedExercises;
        $this->reorderedBy = $reorderedBy;
        $this->reorderedByName = $reorderedByName;
        $this->reorderedAt = time();
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('lobby.' . $this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'ExercisesReordered';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'updated_exercises' => $this->updatedExercises,
            'reordered_by' => $this->reorderedBy,
            'reordered_by_name' => $this->reorderedByName,
            'reordered_at' => $this->reorderedAt,
        ];
    }
}
