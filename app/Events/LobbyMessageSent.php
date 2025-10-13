<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LobbyMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $sessionId;
    public $userId;
    public $userName;
    public $message;
    public $timestamp;
    public $messageId;
    public $isSystemMessage;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $sessionId,
        int $userId,
        string $userName,
        string $message,
        int $timestamp,
        string $messageId,
        bool $isSystemMessage = false
    ) {
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->userName = $userName;
        $this->message = $message;
        $this->timestamp = $timestamp;
        $this->messageId = $messageId;
        $this->isSystemMessage = $isSystemMessage;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('lobby.' . $this->sessionId),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'session_id' => $this->sessionId,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'message' => $this->message,
            'timestamp' => $this->timestamp,
            'is_system_message' => $this->isSystemMessage,
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'LobbyMessageSent';
    }
}
