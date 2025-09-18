<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EngagementService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('ENGAGEMENT_SERVICE_URL', 'http://fitnease-engagement');
    }

    /**
     * Track group interaction events
     */
    public function trackGroupInteraction(string $token, array $interactionData): ?array
    {
        try {
            Log::info('Tracking group interaction with engagement service', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-engagement',
                'interaction_type' => $interactionData['type'] ?? 'unknown',
                'group_id' => $interactionData['group_id'] ?? null
            ]);

            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/api/engagement/group-interaction', $interactionData);

            if ($response->successful()) {
                $result = $response->json();

                Log::info('Group interaction tracked successfully', [
                    'service' => 'fitnease-social',
                    'target_service' => 'fitnease-engagement',
                    'interaction_id' => $result['interaction_id'] ?? null
                ]);

                return $result;
            }

            Log::warning('Failed to track group interaction', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-engagement',
                'status_code' => $response->status(),
                'response_body' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Error tracking group interaction', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-engagement',
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Get group engagement metrics
     */
    public function getGroupEngagementMetrics(string $token, int $groupId): ?array
    {
        try {
            Log::info('Requesting group engagement metrics', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-engagement',
                'group_id' => $groupId
            ]);

            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . "/api/engagement/groups/{$groupId}/metrics");

            if ($response->successful()) {
                $metrics = $response->json();

                Log::info('Group engagement metrics retrieved successfully', [
                    'service' => 'fitnease-social',
                    'target_service' => 'fitnease-engagement',
                    'group_id' => $groupId,
                    'metrics_count' => count($metrics['data'] ?? [])
                ]);

                return $metrics;
            }

            Log::warning('Failed to retrieve group engagement metrics', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-engagement',
                'group_id' => $groupId,
                'status_code' => $response->status()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Error getting group engagement metrics', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-engagement',
                'group_id' => $groupId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Notify about social milestones
     */
    public function notifySocialMilestone(string $token, array $milestoneData): ?array
    {
        try {
            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/api/engagement/social-milestone', $milestoneData);

            if ($response->successful()) {
                return $response->json();
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Error notifying social milestone', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-engagement',
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }
}