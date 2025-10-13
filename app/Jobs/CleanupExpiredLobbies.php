<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use App\Models\WorkoutLobby;
use App\Events\LobbyDeleted;

class CleanupExpiredLobbies implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Running CleanupExpiredLobbies job');

        // Find all lobbies that have expired
        $expiredLobbies = WorkoutLobby::expired()->get();

        Log::info('Found expired lobbies', ['count' => $expiredLobbies->count()]);

        foreach ($expiredLobbies as $lobby) {
            Log::info('Deleting expired lobby', [
                'session_id' => $lobby->session_id,
                'lobby_id' => $lobby->id,
                'expired_at' => $lobby->expires_at,
            ]);

            // Broadcast LobbyDeleted event
            broadcast(new LobbyDeleted($lobby->session_id));

            // Delete the lobby (cascade will delete members and messages)
            $lobby->delete();
        }

        Log::info('Cleanup completed', ['deleted_count' => $expiredLobbies->count()]);
    }
}
