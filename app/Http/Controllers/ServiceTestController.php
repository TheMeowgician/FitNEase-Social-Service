<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\AuthService;
use App\Services\ContentService;
use App\Services\TrackingService;
use App\Services\CommunicationsService;
use App\Services\EngagementService;
use Illuminate\Support\Facades\Log;

class ServiceTestController extends Controller
{
    /**
     * Test all service communications from social service
     */
    public function testAllServices(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('user_id');
        $token = $request->bearerToken();

        Log::info('Social Service - Testing all service communications', [
            'user_id' => $userId,
            'timestamp' => now()
        ]);

        $results = [
            'service' => 'fitnease-social',
            'timestamp' => now(),
            'user_id' => $userId,
            'tests' => []
        ];

        // Test Auth Service
        try {
            $authService = new AuthService();
            $userProfile = $authService->getUserProfile($token, $userId);

            $results['tests']['auth_service'] = [
                'status' => $userProfile ? 'success' : 'failed',
                'test' => 'getUserProfile(' . $userId . ')',
                'response' => $userProfile ? 'User profile retrieved' : 'No data returned',
                'data' => $userProfile
            ];
        } catch (\Exception $e) {
            $results['tests']['auth_service'] = [
                'status' => 'error',
                'test' => 'getUserProfile(' . $userId . ')',
                'error' => $e->getMessage()
            ];
        }

        // Test Content Service
        try {
            $contentService = new ContentService();
            $workout = $contentService->getWorkout($token, 1);

            $results['tests']['content_service'] = [
                'status' => $workout ? 'success' : 'failed',
                'test' => 'getWorkout(1)',
                'response' => $workout ? 'Workout data retrieved' : 'No data returned',
                'data' => $workout
            ];
        } catch (\Exception $e) {
            $results['tests']['content_service'] = [
                'status' => 'error',
                'test' => 'getWorkout(1)',
                'error' => $e->getMessage()
            ];
        }

        // Test Tracking Service
        try {
            $trackingService = new TrackingService();
            $groupWorkouts = $trackingService->getGroupWorkouts($token, '1');

            $results['tests']['tracking_service'] = [
                'status' => $groupWorkouts ? 'success' : 'failed',
                'test' => 'getGroupWorkouts(1)',
                'response' => $groupWorkouts ? 'Group workouts retrieved' : 'No data returned',
                'data' => $groupWorkouts
            ];
        } catch (\Exception $e) {
            $results['tests']['tracking_service'] = [
                'status' => 'error',
                'test' => 'getGroupWorkouts(1)',
                'error' => $e->getMessage()
            ];
        }

        // Test Communications Service
        try {
            $commsService = new CommunicationsService();
            // Test a simple notification
            $notificationData = [
                'user_id' => $userId,
                'message' => 'Test group notification',
                'type' => 'group_invite'
            ];
            $notification = $commsService->sendGroupNotification($token, $notificationData);

            $results['tests']['communications_service'] = [
                'status' => $notification ? 'success' : 'failed',
                'test' => 'sendGroupNotification()',
                'response' => $notification ? 'Notification sent' : 'Notification failed',
                'data' => $notification
            ];
        } catch (\Exception $e) {
            $results['tests']['communications_service'] = [
                'status' => 'error',
                'test' => 'sendGroupNotification()',
                'error' => $e->getMessage()
            ];
        }

        // Test Engagement Service
        try {
            $engagementService = new EngagementService();
            $metrics = $engagementService->getGroupEngagementMetrics($token, 1);

            $results['tests']['engagement_service'] = [
                'status' => $metrics ? 'success' : 'failed',
                'test' => 'getGroupEngagementMetrics(1)',
                'response' => $metrics ? 'Engagement metrics retrieved' : 'No data returned',
                'data' => $metrics
            ];
        } catch (\Exception $e) {
            $results['tests']['engagement_service'] = [
                'status' => 'error',
                'test' => 'getGroupEngagementMetrics(1)',
                'error' => $e->getMessage()
            ];
        }

        // Summary
        $successCount = collect($results['tests'])->filter(function ($test) {
            return $test['status'] === 'success';
        })->count();

        $results['summary'] = [
            'total_tests' => count($results['tests']),
            'successful_tests' => $successCount,
            'failed_tests' => count($results['tests']) - $successCount,
            'success_rate' => count($results['tests']) > 0 ? round(($successCount / count($results['tests'])) * 100, 2) . '%' : '0%'
        ];

        Log::info('Social Service - Service communication tests completed', [
            'user_id' => $userId,
            'successful_tests' => $successCount,
            'total_tests' => count($results['tests'])
        ]);

        return response()->json($results);
    }

    /**
     * Test specific service communication
     */
    public function testSpecificService(Request $request, $serviceName): JsonResponse
    {
        $userId = $request->attributes->get('user_id');
        $token = $request->bearerToken();

        Log::info("Social Service - Testing specific service: {$serviceName}", [
            'user_id' => $userId,
            'service' => $serviceName
        ]);

        $result = [
            'service' => 'fitnease-social',
            'target_service' => $serviceName,
            'timestamp' => now(),
            'user_id' => $userId
        ];

        try {
            switch ($serviceName) {
                case 'auth':
                    $authService = new AuthService();
                    $data = $authService->getUserProfile($token, $userId);
                    $result['status'] = $data ? 'success' : 'failed';
                    $result['data'] = $data;
                    break;

                case 'content':
                    $contentService = new ContentService();
                    $data = $contentService->getWorkoutsBatch($token, [1, 2, 3]);
                    $result['status'] = $data ? 'success' : 'failed';
                    $result['data'] = $data;
                    break;

                case 'tracking':
                    $trackingService = new TrackingService();
                    $data = $trackingService->getGroupLeaderboard($token, [
                        'group_id' => 1,
                        'time_period' => 'week'
                    ]);
                    $result['status'] = $data ? 'success' : 'failed';
                    $result['data'] = $data;
                    break;

                case 'communications':
                    $commsService = new CommunicationsService();
                    $data = $commsService->sendGroupNotification($token, [
                        'user_id' => $userId,
                        'message' => 'Test notification',
                        'type' => 'test'
                    ]);
                    $result['status'] = $data ? 'success' : 'failed';
                    $result['data'] = $data;
                    break;

                case 'engagement':
                    $engagementService = new EngagementService();
                    $data = $engagementService->getGroupEngagementMetrics($token, 1);
                    $result['status'] = $data ? 'success' : 'failed';
                    $result['data'] = $data;
                    break;

                default:
                    $result['status'] = 'error';
                    $result['error'] = 'Unknown service: ' . $serviceName;
            }
        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();

            Log::error("Social Service - Error testing service: {$serviceName}", [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
        }

        return response()->json($result);
    }
}