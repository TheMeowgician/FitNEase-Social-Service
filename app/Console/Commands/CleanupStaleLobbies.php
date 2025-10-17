<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WorkoutLobby;
use Illuminate\Support\Facades\Log;

class CleanupStaleLobbies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lobbies:cleanup {--hours=2 : Age in hours for stale lobbies}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up stale workout lobbies that are older than specified hours';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hours = $this->option('hours');
        $cutoffTime = now()->subHours($hours);

        $this->info("Cleaning up lobbies older than {$hours} hours (before {$cutoffTime})...");

        // Mark stale lobbies as completed
        $count = WorkoutLobby::where('status', '!=', 'completed')
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '<', $cutoffTime)
            ->update(['status' => 'completed']);

        $this->info("Marked {$count} stale lobbies as completed");

        Log::info('LOBBY_CLEANUP', [
            'count' => $count,
            'hours' => $hours,
            'cutoff_time' => $cutoffTime->toIso8601String()
        ]);

        return Command::SUCCESS;
    }
}
