<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LobbyMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public ?int $userId; // Null for system messages
    public string $userName;
    public string $message;
    public int $timestamp;
    public string $messageId;
    public bool $isSystemMessage;

    public function __construct(
        string $sessionId,
        ?int $userId,
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

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('lobby.' . $this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'LobbyMessageSent';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'message' => $this->message,
            'timestamp' => $this->timestamp,
            'message_id' => $this->messageId,
            'is_system_message' => $this->isSystemMessage,
        ];
    }
}
