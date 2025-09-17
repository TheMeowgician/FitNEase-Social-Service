<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrackingService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('TRACKING_SERVICE_URL');
    }

    /**
     * Get group workouts from tracking service
     */
    public function getGroupWorkouts(string $token, string $groupId): ?array
    {
        try {
            Log::info('Requesting group workouts from tracking service', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-tracking',
                'group_id' => $groupId,
                'token_prefix' => substr($token, 0, 10) . '...'
            ]);

            $response = Http::timeout(15)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . "/api/tracking/group-workouts/{$groupId}");

            if ($response->successful()) {
                $workoutData = $response->json();

                Log::info('Group workouts retrieved successfully', [
                    'service' => 'fitnease-social',
                    'target_service' => 'fitnease-tracking',
                    'group_id' => $groupId,
                    'workout_count' => count($workoutData['data'] ?? [])
                ]);

                return $workoutData;
            }

            Log::warning('Failed to retrieve group workouts', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-tracking',
                'group_id' => $groupId,
                'status_code' => $response->status(),
                'response_body' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Error communicating with tracking service', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-tracking',
                'group_id' => $groupId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Start a group workout session
     */
    public function startGroupWorkoutSession(string $token, array $sessionData): ?array
    {
        try {
            Log::info('Starting group workout session', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-tracking',
                'session_data' => $sessionData
            ]);

            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/api/tracking/group-workout-session', $sessionData);

            if ($response->successful()) {
                $sessionResult = $response->json();

                Log::info('Group workout session started successfully', [
                    'service' => 'fitnease-social',
                    'target_service' => 'fitnease-tracking',
                    'session_id' => $sessionResult['session_id'] ?? null
                ]);

                return $sessionResult;
            }

            Log::warning('Failed to start group workout session', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-tracking',
                'status_code' => $response->status(),
                'response_body' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Error starting group workout session', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-tracking',
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Get group leaderboard data
     */
    public function getGroupLeaderboard(string $token, array $leaderboardData): ?array
    {
        try {
            Log::info('Requesting group leaderboard from tracking service', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-tracking',
                'leaderboard_data' => $leaderboardData
            ]);

            $response = Http::timeout(15)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/api/tracking/group-leaderboard', $leaderboardData);

            if ($response->successful()) {
                $leaderboardResult = $response->json();

                Log::info('Group leaderboard retrieved successfully', [
                    'service' => 'fitnease-social',
                    'target_service' => 'fitnease-tracking',
                    'member_count' => count($leaderboardResult['data'] ?? [])
                ]);

                return $leaderboardResult;
            }

            Log::warning('Failed to retrieve group leaderboard', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-tracking',
                'status_code' => $response->status(),
                'response_body' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Error communicating with tracking service for leaderboard', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-tracking',
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }
}