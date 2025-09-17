<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('CONTENT_SERVICE_URL');
    }

    /**
     * Get workout details in batch
     */
    public function getWorkoutsBatch(string $token, array $workoutIds): ?array
    {
        try {
            Log::info('Requesting workout batch from content service', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-content',
                'workout_ids' => $workoutIds,
                'workout_count' => count($workoutIds)
            ]);

            $response = Http::timeout(15)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/api/content/workouts/batch', [
                'workout_ids' => $workoutIds
            ]);

            if ($response->successful()) {
                $workoutData = $response->json();

                Log::info('Workout batch retrieved successfully', [
                    'service' => 'fitnease-social',
                    'target_service' => 'fitnease-content',
                    'requested_count' => count($workoutIds),
                    'returned_count' => count($workoutData['data'] ?? [])
                ]);

                return $workoutData;
            }

            Log::warning('Failed to retrieve workout batch', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-content',
                'workout_ids' => $workoutIds,
                'status_code' => $response->status(),
                'response_body' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Error communicating with content service', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-content',
                'workout_ids' => $workoutIds,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Get single workout details
     */
    public function getWorkout(string $token, int $workoutId): ?array
    {
        try {
            Log::info('Requesting workout details from content service', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-content',
                'workout_id' => $workoutId
            ]);

            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . "/api/content/workouts/{$workoutId}");

            if ($response->successful()) {
                $workoutData = $response->json();

                Log::info('Workout details retrieved successfully', [
                    'service' => 'fitnease-social',
                    'target_service' => 'fitnease-content',
                    'workout_id' => $workoutId,
                    'workout_title' => $workoutData['title'] ?? 'Unknown'
                ]);

                return $workoutData;
            }

            Log::warning('Failed to retrieve workout details', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-content',
                'workout_id' => $workoutId,
                'status_code' => $response->status(),
                'response_body' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Error communicating with content service for workout', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-content',
                'workout_id' => $workoutId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }
}