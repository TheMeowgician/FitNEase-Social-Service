<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ServiceCommunicationTestController extends Controller
{
    /**
     * Test service connectivity from social service
     */
    public function testServiceConnectivity(Request $request): JsonResponse
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No authentication token provided'
                ], 401);
            }

            $services = [
                'auth' => env('AUTH_SERVICE_URL', 'http://fitnease-auth'),
                'content' => env('CONTENT_SERVICE_URL', 'http://fitnease-content'),
                'tracking' => env('TRACKING_SERVICE_URL', 'http://fitnease-tracking'),
                'engagement' => env('ENGAGEMENT_SERVICE_URL', 'http://fitnease-engagement'),
                'communications' => env('COMMS_SERVICE_URL', 'http://fitnease-comms')
            ];

            $connectivity = [];

            foreach ($services as $serviceName => $serviceUrl) {
                try {
                    $response = Http::timeout(10)->withHeaders([
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json'
                    ])->get($serviceUrl . '/api/health');

                    $connectivity[$serviceName] = [
                        'url' => $serviceUrl,
                        'status' => $response->successful() ? 'connected' : 'failed',
                        'response_code' => $response->status(),
                        'response_time' => $response->handlerStats()['total_time'] ?? 'unknown'
                    ];

                } catch (\Exception $e) {
                    $connectivity[$serviceName] = [
                        'url' => $serviceUrl,
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ];
                }
            }

            $overallHealth = true;
            foreach ($connectivity as $service) {
                if ($service['status'] !== 'connected') {
                    $overallHealth = false;
                    break;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Service connectivity test completed',
                'overall_health' => $overallHealth ? 'healthy' : 'degraded',
                'services' => $connectivity,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Service connectivity test failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test incoming communications to social service
     */
    public function testIncomingCommunications(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('user_id');

        Log::info('Social Service - Testing incoming communications', [
            'user_id' => $userId,
            'timestamp' => now()
        ]);

        $results = [
            'service' => 'fitnease-social',
            'test_type' => 'incoming_communications',
            'timestamp' => now(),
            'user_id' => $userId,
            'simulations' => []
        ];

        // Simulate Engagement Service requesting group activity data
        try {
            $groupActivity = [
                'group_id' => 1,
                'daily_active_members' => 15,
                'weekly_interactions' => 48,
                'popular_workouts' => ['Push-Pull Split', 'HIIT Cardio'],
                'engagement_score' => 8.7
            ];

            $results['simulations']['engagement_service_activity_request'] = [
                'status' => 'success',
                'simulation' => 'Engagement Service requesting group activity metrics',
                'endpoint' => '/social/group-activity/1',
                'method' => 'GET',
                'response_data' => $groupActivity,
                'metadata' => [
                    'caller_service' => 'fitnease-engagement',
                    'purpose' => 'Group engagement analysis and motivation features'
                ]
            ];
        } catch (\Exception $e) {
            $results['simulations']['engagement_service_activity_request'] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }

        // Simulate Tracking Service requesting group workout data
        try {
            $groupWorkouts = [
                'group_id' => 1,
                'active_sessions' => 3,
                'completed_workouts_today' => 12,
                'group_challenges' => [
                    ['name' => 'November Fitness Challenge', 'participants' => 8],
                    ['name' => 'Team Cardio Competition', 'participants' => 12]
                ]
            ];

            $results['simulations']['tracking_service_workouts_request'] = [
                'status' => 'success',
                'simulation' => 'Tracking Service requesting group workout data',
                'endpoint' => '/social/group-workouts/1',
                'method' => 'GET',
                'response_data' => $groupWorkouts,
                'metadata' => [
                    'caller_service' => 'fitnease-tracking',
                    'purpose' => 'Group workout analytics and leaderboard generation'
                ]
            ];
        } catch (\Exception $e) {
            $results['simulations']['tracking_service_workouts_request'] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }

        // Simulate Communications Service requesting group member data
        try {
            $groupMembers = [
                'group_id' => 1,
                'member_count' => 25,
                'notification_preferences' => [
                    'workout_reminders' => 20,
                    'achievement_alerts' => 22,
                    'challenge_updates' => 18
                ],
                'active_members' => [
                    ['user_id' => 1, 'name' => 'John Doe', 'role' => 'admin'],
                    ['user_id' => 2, 'name' => 'Jane Smith', 'role' => 'member']
                ]
            ];

            $results['simulations']['communications_service_members_request'] = [
                'status' => 'success',
                'simulation' => 'Communications Service requesting group member data for notifications',
                'endpoint' => '/social/groups/1/members',
                'method' => 'GET',
                'response_data' => $groupMembers,
                'metadata' => [
                    'caller_service' => 'fitnease-comms',
                    'purpose' => 'Group notification delivery and member communication'
                ]
            ];
        } catch (\Exception $e) {
            $results['simulations']['communications_service_members_request'] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }

        // Simulate Content Service requesting popular group workouts
        try {
            $popularWorkouts = [
                'group_id' => 1,
                'trending_workouts' => [
                    ['workout_id' => 101, 'title' => 'HIIT Blast', 'popularity_score' => 9.2],
                    ['workout_id' => 102, 'title' => 'Strength Builder', 'popularity_score' => 8.8],
                    ['workout_id' => 103, 'title' => 'Cardio Crusher', 'popularity_score' => 8.5]
                ],
                'group_preferences' => [
                    'preferred_duration' => 45,
                    'preferred_intensity' => 'moderate_high',
                    'equipment_availability' => 'full_gym'
                ]
            ];

            $results['simulations']['content_service_popular_request'] = [
                'status' => 'success',
                'simulation' => 'Content Service requesting popular group workouts',
                'endpoint' => '/social/groups/1/popular-workouts',
                'method' => 'GET',
                'response_data' => $popularWorkouts,
                'metadata' => [
                    'caller_service' => 'fitnease-content',
                    'purpose' => 'Personalized content recommendations for group members'
                ]
            ];
        } catch (\Exception $e) {
            $results['simulations']['content_service_popular_request'] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }

        // Summary
        $successCount = collect($results['simulations'])->filter(function ($simulation) {
            return $simulation['status'] === 'success';
        })->count();

        $results['summary'] = [
            'total_simulations' => count($results['simulations']),
            'successful_simulations' => $successCount,
            'failed_simulations' => count($results['simulations']) - $successCount,
            'success_rate' => count($results['simulations']) > 0 ? round(($successCount / count($results['simulations'])) * 100, 2) . '%' : '0%'
        ];

        Log::info('Social Service - Incoming communication tests completed', [
            'user_id' => $userId,
            'successful_simulations' => $successCount,
            'total_simulations' => count($results['simulations'])
        ]);

        return response()->json($results);
    }

    /**
     * Test social service token validation
     */
    public function testSocialTokenValidation(Request $request): JsonResponse
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No authentication token provided'
                ], 401);
            }

            $user = $request->attributes->get('user');

            return response()->json([
                'success' => true,
                'message' => 'Token validation successful in social service',
                'social_service_status' => 'connected',
                'user_data' => $user,
                'token_info' => [
                    'token_preview' => substr($token, 0, 10) . '...',
                    'validated_at' => now()->toISOString()
                ],
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Social token validation test failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}