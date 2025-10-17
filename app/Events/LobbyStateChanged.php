<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LobbyStateChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sessionId;
    public array $lobbyState;

    public function __construct(string $sessionId, array $lobbyState)
    {
        $this->sessionId = $sessionId;
        $this->lobbyState = $lobbyState;
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('lobby.' . $this->sessionId);
    }

    public function broadcastAs(): string
    {
        return 'LobbyStateChanged';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'lobby_state' => $this->lobbyState,
        ];
    }
}
