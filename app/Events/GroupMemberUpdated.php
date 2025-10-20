<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time Group Member Update Event
 *
 * Broadcasts when a member joins or leaves a group
 * for instant member list updates without page refresh
 */
class GroupMemberUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $groupId;
    public int $memberCount;
    public array $members;

    /**
     * Create a new event instance.
     */
    public function __construct(int $groupId, int $memberCount, array $members)
    {
        $this->groupId = $groupId;
        $this->memberCount = $memberCount;
        $this->members = $members;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('group.' . $this->groupId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'group.members.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'group_id' => $this->groupId,
            'member_count' => $this->memberCount,
            'members' => $this->members,
            'timestamp' => time(),
        ];
    }
}
