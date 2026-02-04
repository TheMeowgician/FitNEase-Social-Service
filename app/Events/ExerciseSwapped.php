<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Exercise Swapped
 *
 * Broadcast when the initiator/mentor swaps an exercise during group customization.
 * All lobby members receive this event to update their exercise list in real-time.
 *
 * This event is only triggered after the group has voted "customize".
 */
class ExerciseSwapped implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public int $slotIndex;
    public array $oldExercise;
    public array $newExercise;
    public int $swappedBy;
    public string $swappedByName;
    public int $swappedAt;
    public array $updatedExercises; // Full updated exercise list

    public function __construct(
        string $sessionId,
        int $slotIndex,
        array $oldExercise,
        array $newExercise,
        int $swappedBy,
        string $swappedByName,
        array $updatedExercises
    ) {
        $this->sessionId = $sessionId;
        $this->slotIndex = $slotIndex;
        $this->oldExercise = $oldExercise;
        $this->newExercise = $newExercise;
        $this->swappedBy = $swappedBy;
        $this->swappedByName = $swappedByName;
        $this->swappedAt = time();
        $this->updatedExercises = $updatedExercises;
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('lobby.' . $this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'ExerciseSwapped';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'slot_index' => $this->slotIndex,
            'old_exercise' => $this->oldExercise,
            'new_exercise' => $this->newExercise,
            'swapped_by' => $this->swappedBy,
            'swapped_by_name' => $this->swappedByName,
            'swapped_at' => $this->swappedAt,
            'updated_exercises' => $this->updatedExercises,
        ];
    }
}
