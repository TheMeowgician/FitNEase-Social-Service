<?php

namespace App\Console\Commands;

use App\Models\WorkoutSession;
use App\Events\WorkoutSessionTick;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * High-Performance Server-Authoritative Timer
 *
 * Runs as background process (Supervisor managed)
 * Ticks all active workout sessions every second
 * Broadcasts state via WebSocket for instant synchronization
 */
class WorkoutSessionTimer extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'workout:timer
                            {--once : Run once instead of loop}';

    /**
     * The console command description.
     */
    protected $description = 'Server-authoritative workout timer - broadcasts state every second';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🏋️ Workout Session Timer Started');
        $this->info('📡 Broadcasting timer updates via WebSocket');
        $this->info('⏱️  Tick interval: 1 second');
        $this->info('');

        $tickCount = 0;

        // Run forever (Supervisor will restart if crashes)
        while (true) {
            $startTime = microtime(true);

            try {
                $this->tick();
                $tickCount++;

                if ($tickCount % 60 === 0) {
                    $this->info("✅ Processed {$tickCount} ticks");
                }
            } catch (\Exception $e) {
                Log::error('Workout timer tick failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->error("❌ Error: {$e->getMessage()}");
            }

            // Calculate sleep time to maintain 1 second interval
            $elapsedMs = (microtime(true) - $startTime) * 1000;
            $sleepMs = max(0, 1000 - $elapsedMs);

            // Exit after one iteration if --once flag
            if ($this->option('once')) {
                return 0;
            }

            // Sleep to maintain 1Hz tick rate
            usleep($sleepMs * 1000);
        }
    }

    /**
     * Process one tick cycle
     */
    protected function tick(): void
    {
        // Get all running sessions
        // CRITICAL: Must select workout_data for phase transitions!
        $runningSessions = WorkoutSession::where('status', 'running')
            ->get(['id', 'session_id', 'time_remaining', 'phase', 'current_exercise',
                   'current_set', 'current_round', 'calories_burned', 'status', 'workout_data']);

        if ($runningSessions->isEmpty()) {
            return;
        }

        foreach ($runningSessions as $session) {
            try {
                // Decrement timer
                $continues = $session->tick();

                if (!$continues) {
                    // Timer reached 0, handle phase transition
                    $this->handlePhaseComplete($session);

                    // CRITICAL: Refresh session from DB to get updated phase/time
                    $session->refresh();
                }

                // Broadcast current state to all clients
                broadcast(new WorkoutSessionTick($session->getCurrentState()));

            } catch (\Exception $e) {
                Log::error('Failed to tick session', [
                    'session_id' => $session->session_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle phase completion (work → rest → next exercise)
     */
    protected function handlePhaseComplete(WorkoutSession $session): void
    {
        $workoutData = $session->workout_data;

        if (!$workoutData || !isset($workoutData['exercises'])) {
            Log::error('Session missing workout data - cannot transition phases', [
                'session_id' => $session->session_id,
                'phase' => $session->phase,
                'time_remaining' => $session->time_remaining,
            ]);
            // Stop the session if no workout data
            $session->stop();
            return;
        }

        $totalExercises = count($workoutData['exercises']);

        switch ($session->phase) {
            case 'prepare':
                // Prepare → Work phase
                $session->update([
                    'phase' => 'work',
                    'time_remaining' => 20, // 20 seconds work
                ]);
                break;

            case 'work':
                // Work → Rest phase (or next exercise)
                if ($session->current_set >= 7) {
                    // 8 sets completed, move to next exercise
                    if ($session->current_exercise >= $totalExercises - 1) {
                        // Workout complete!
                        $session->complete();
                        $this->info("✅ Session {$session->session_id} completed!");
                    } else {
                        // Next exercise with 60 second rest
                        $session->update([
                            'phase' => 'rest',
                            'time_remaining' => 60,
                            'current_exercise' => $session->current_exercise + 1,
                            'current_set' => 0,
                        ]);
                    }
                } else {
                    // Short rest between sets (10 seconds)
                    $session->update([
                        'phase' => 'rest',
                        'time_remaining' => 10,
                        'current_set' => $session->current_set + 1,
                    ]);
                }
                break;

            case 'rest':
                // Rest → Work phase
                $session->update([
                    'phase' => 'work',
                    'time_remaining' => 20,
                ]);
                break;
        }
    }
}
