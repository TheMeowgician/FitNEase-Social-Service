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
        $expiredLobbies = WorkoutLobby::where('status', 'waiting')
            ->where('expires_at', '<', now())
            ->get();

        // Find abandoned lobbies (waiting lobbies with no active members)
        $abandonedLobbies = WorkoutLobby::where('status', 'waiting')
            ->whereDoesntHave('activeMembers')
            ->get();

        $totalToClean = $expiredLobbies->count() + $abandonedLobbies->count();

        Log::info('Found lobbies to clean', [
            'expired_count' => $expiredLobbies->count(),
            'abandoned_count' => $abandonedLobbies->count(),
            'total' => $totalToClean
        ]);

        // Clean up expired lobbies
        foreach ($expiredLobbies as $lobby) {
            Log::info('Cleaning up expired lobby', [
                'session_id' => $lobby->session_id,
                'lobby_id' => $lobby->id,
                'expired_at' => $lobby->expires_at,
            ]);

            $lobby->update(['status' => 'cancelled']);
            $lobby->members()->update([
                'is_active' => false,
                'status' => 'left',
                'left_at' => now(),
                'left_reason' => 'Lobby expired'
            ]);

            broadcast(new LobbyDeleted($lobby->session_id, 'Lobby expired', time()));
        }

        // Clean up abandoned lobbies
        foreach ($abandonedLobbies as $lobby) {
            Log::info('Cleaning up abandoned lobby', [
                'session_id' => $lobby->session_id,
                'lobby_id' => $lobby->id,
                'created_at' => $lobby->created_at,
            ]);

            $lobby->update(['status' => 'cancelled']);
            broadcast(new LobbyDeleted($lobby->session_id, 'Lobby abandoned', time()));
        }

        Log::info('Cleanup completed', ['cleaned_count' => $totalToClean]);
    }
}
