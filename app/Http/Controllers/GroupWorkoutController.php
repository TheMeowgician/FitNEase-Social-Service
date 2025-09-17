<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupWorkoutEvaluation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use GuzzleHttp\Client;
use Carbon\Carbon;

class GroupWorkoutController extends Controller
{
    public function getGroupWorkouts(string $groupId, Request $request): JsonResponse
    {
        $group = Group::where('group_id', $groupId)->active()->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group not found'
            ], 404);
        }

        $membership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $request->attributes->get('user_id'))
            ->where('is_active', true)
            ->first();

        if (!$membership && $group->is_private) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied to private group'
            ], 403);
        }

        try {
            $client = new Client();
            $response = $client->get(env('TRACKING_SERVICE_URL') . '/tracking/group-workouts/' . $groupId, [
                'headers' => [
                    'Authorization' => request()->header('Authorization'),
                    'Accept' => 'application/json'
                ]
            ]);

            $workoutSessions = json_decode($response->getBody(), true);

            $workoutIds = collect($workoutSessions['data'] ?? [])->pluck('workout_id')->unique();

            $evaluationStats = [];
            foreach ($workoutIds as $workoutId) {
                $evaluationStats[$workoutId] = GroupWorkoutEvaluation::getWorkoutStats($groupId, $workoutId);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'workout_sessions' => $workoutSessions['data'] ?? [],
                    'evaluation_stats' => $evaluationStats
                ],
                'message' => 'Group workouts retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve group workouts'
            ], 500);
        }
    }

    public function createWorkoutEvaluation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'group_id' => 'required|integer|exists:groups,group_id',
            'workout_id' => 'required|integer',
            'evaluation_type' => 'required|in:like,unlike',
            'comment' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $group = Group::where('group_id', $request->group_id)->active()->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group not found'
            ], 404);
        }

        $membership = GroupMember::where('group_id', $request->group_id)
            ->where('user_id', $request->attributes->get('user_id'))
            ->where('is_active', true)
            ->first();

        if (!$membership) {
            return response()->json([
                'status' => 'error',
                'message' => 'Must be a group member to evaluate workouts'
            ], 403);
        }

        $moderationResult = $this->moderateGroupActivity($request->group_id, [
            'user_id' => $request->attributes->get('user_id'),
            'comment' => $request->comment
        ]);

        if (!$moderationResult['approved']) {
            return response()->json([
                'status' => 'error',
                'message' => 'Content flagged for moderation',
                'flags' => $moderationResult['flags']
            ], 400);
        }

        $existingEvaluation = GroupWorkoutEvaluation::where('group_id', $request->group_id)
            ->where('workout_id', $request->workout_id)
            ->where('user_id', $request->attributes->get('user_id'))
            ->first();

        if ($existingEvaluation) {
            $existingEvaluation->update([
                'evaluation_type' => $request->evaluation_type,
                'comment' => $request->comment
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $existingEvaluation->fresh(['group']),
                'message' => 'Workout evaluation updated successfully'
            ]);
        }

        $evaluation = GroupWorkoutEvaluation::create([
            'group_id' => $request->group_id,
            'workout_id' => $request->workout_id,
            'user_id' => $request->attributes->get('user_id'),
            'evaluation_type' => $request->evaluation_type,
            'comment' => $request->comment
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $evaluation->load(['group']),
            'message' => 'Workout evaluation created successfully'
        ], 201);
    }

    public function getGroupLeaderboard(string $groupId): JsonResponse
    {
        $group = Group::where('group_id', $groupId)->active()->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group not found'
            ], 404);
        }

        $membership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $request->attributes->get('user_id'))
            ->where('is_active', true)
            ->first();

        if (!$membership && $group->is_private) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied to private group'
            ], 403);
        }

        try {
            $memberIds = GroupMember::where('group_id', $groupId)
                ->where('is_active', true)
                ->pluck('user_id');

            $client = new Client();
            $response = $client->post(env('TRACKING_SERVICE_URL') . '/tracking/group-leaderboard', [
                'json' => [
                    'group_id' => $groupId,
                    'member_ids' => $memberIds->toArray(),
                    'period' => 'week'
                ],
                'headers' => [
                    'Authorization' => request()->header('Authorization'),
                    'Accept' => 'application/json'
                ]
            ]);

            $leaderboardData = json_decode($response->getBody(), true);

            $socialStats = $this->getGroupSocialStats($groupId, $memberIds);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'leaderboard' => $leaderboardData['data'] ?? [],
                    'social_stats' => $socialStats,
                    'period' => 'week'
                ],
                'message' => 'Group leaderboard retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve group leaderboard'
            ], 500);
        }
    }

    public function getGroupChallenges(string $groupId): JsonResponse
    {
        $group = Group::where('group_id', $groupId)->active()->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group not found'
            ], 404);
        }

        $membership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $request->attributes->get('user_id'))
            ->where('is_active', true)
            ->first();

        if (!$membership && $group->is_private) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied to private group'
            ], 403);
        }

        $activeChallenges = $this->getActiveChallenges($groupId);
        $completedChallenges = $this->getCompletedChallenges($groupId);

        return response()->json([
            'status' => 'success',
            'data' => [
                'active_challenges' => $activeChallenges,
                'completed_challenges' => $completedChallenges,
                'group_challenge_stats' => $this->getGroupChallengeStats($groupId)
            ],
            'message' => 'Group challenges retrieved successfully'
        ]);
    }

    public function joinGroupWorkout(string $groupId, string $workoutId): JsonResponse
    {
        $membership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $request->attributes->get('user_id'))
            ->where('is_active', true)
            ->first();

        if (!$membership) {
            return response()->json([
                'status' => 'error',
                'message' => 'Not a group member'
            ], 403);
        }

        try {
            $client = new Client();
            $response = $client->post(env('TRACKING_SERVICE_URL') . '/tracking/group-workout-session', [
                'json' => [
                    'user_id' => $request->attributes->get('user_id'),
                    'workout_id' => $workoutId,
                    'group_id' => $groupId,
                    'session_type' => 'group'
                ],
                'headers' => [
                    'Authorization' => request()->header('Authorization'),
                    'Accept' => 'application/json'
                ]
            ]);

            return response()->json([
                'status' => 'success',
                'data' => json_decode($response->getBody(), true),
                'message' => 'Group workout session started'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to start group workout session'
            ], 500);
        }
    }

    public function getWorkoutEvaluations(string $groupId, string $workoutId): JsonResponse
    {
        $group = Group::where('group_id', $groupId)->active()->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group not found'
            ], 404);
        }

        $membership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $request->attributes->get('user_id'))
            ->where('is_active', true)
            ->first();

        if (!$membership && $group->is_private) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied to private group'
            ], 403);
        }

        $evaluations = GroupWorkoutEvaluation::where('group_id', $groupId)
            ->where('workout_id', $workoutId)
            ->latest()
            ->get();

        $stats = GroupWorkoutEvaluation::getWorkoutStats($groupId, $workoutId);
        $userEvaluation = GroupWorkoutEvaluation::getUserEvaluation($groupId, $workoutId, $request->attributes->get('user_id'));

        return response()->json([
            'status' => 'success',
            'data' => [
                'evaluations' => $evaluations,
                'stats' => $stats,
                'user_evaluation' => $userEvaluation
            ],
            'message' => 'Workout evaluations retrieved successfully'
        ]);
    }

    public function getPopularWorkouts(string $groupId): JsonResponse
    {
        $group = Group::where('group_id', $groupId)->active()->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group not found'
            ], 404);
        }

        $membership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $request->attributes->get('user_id'))
            ->where('is_active', true)
            ->first();

        if (!$membership && $group->is_private) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied to private group'
            ], 403);
        }

        $popularWorkouts = GroupWorkoutEvaluation::getPopularWorkouts($groupId, 10);

        try {
            $workoutIds = $popularWorkouts->pluck('workout_id');

            if ($workoutIds->isNotEmpty()) {
                $client = new Client();
                $response = $client->post(env('CONTENT_SERVICE_URL') . '/content/workouts/batch', [
                    'json' => ['workout_ids' => $workoutIds->toArray()],
                    'headers' => [
                        'Authorization' => request()->header('Authorization'),
                        'Accept' => 'application/json'
                    ]
                ]);

                $workoutsData = json_decode($response->getBody(), true);

                $workoutsMap = collect($workoutsData['data'] ?? [])->keyBy('workout_id');

                $popularWorkoutsWithDetails = $popularWorkouts->map(function($workout) use ($workoutsMap) {
                    $workoutDetails = $workoutsMap->get($workout->workout_id, []);

                    return [
                        'workout_id' => $workout->workout_id,
                        'workout_details' => $workoutDetails,
                        'evaluation_stats' => [
                            'total_evaluations' => $workout->total_evaluations,
                            'likes' => $workout->likes,
                            'unlikes' => $workout->unlikes,
                            'like_percentage' => $workout->like_percentage
                        ]
                    ];
                });

                return response()->json([
                    'status' => 'success',
                    'data' => $popularWorkoutsWithDetails,
                    'message' => 'Popular workouts retrieved successfully'
                ]);
            }

        } catch (\Exception $e) {
            \Log::warning('Failed to fetch workout details: ' . $e->getMessage());
        }

        return response()->json([
            'status' => 'success',
            'data' => $popularWorkouts,
            'message' => 'Popular workouts retrieved successfully'
        ]);
    }

    private function moderateGroupActivity(string $groupId, array $activityData): array
    {
        $moderationFlags = [];

        $recentEvaluations = GroupWorkoutEvaluation::where('group_id', $groupId)
            ->where('user_id', $activityData['user_id'])
            ->where('created_at', '>=', Carbon::now()->subMinutes(5))
            ->count();

        if ($recentEvaluations > 10) {
            $moderationFlags[] = 'potential_spam';
        }

        if (isset($activityData['comment']) && $this->containsInappropriateContent($activityData['comment'])) {
            $moderationFlags[] = 'inappropriate_language';
        }

        return [
            'approved' => empty($moderationFlags),
            'flags' => $moderationFlags,
            'action_required' => !empty($moderationFlags)
        ];
    }

    private function containsInappropriateContent(string $content): bool
    {
        $inappropriateWords = ['spam', 'hate', 'abuse'];

        foreach ($inappropriateWords as $word) {
            if (stripos($content, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    private function getGroupSocialStats(string $groupId, $memberIds): array
    {
        $weeklyEvaluations = GroupWorkoutEvaluation::where('group_id', $groupId)
            ->where('created_at', '>=', Carbon::now()->subWeek())
            ->get();

        $memberEvaluations = $weeklyEvaluations->groupBy('user_id')->map(function($evaluations) {
            return [
                'total_evaluations' => $evaluations->count(),
                'positive_evaluations' => $evaluations->where('evaluation_type', 'like')->count(),
                'comments_shared' => $evaluations->whereNotNull('comment')->where('comment', '!=', '')->count()
            ];
        });

        return [
            'total_weekly_evaluations' => $weeklyEvaluations->count(),
            'active_evaluators' => $memberEvaluations->count(),
            'positive_feedback_rate' => $weeklyEvaluations->count() > 0
                ? round(($weeklyEvaluations->where('evaluation_type', 'like')->count() / $weeklyEvaluations->count()) * 100, 1)
                : 0,
            'member_evaluations' => $memberEvaluations
        ];
    }

    private function getActiveChallenges(string $groupId): array
    {
        return [
            [
                'challenge_id' => 1,
                'challenge_type' => 'workout_count',
                'title' => 'Weekly Workout Challenge',
                'description' => 'Complete 5 workouts this week',
                'target_value' => 5,
                'progress' => 3,
                'end_date' => Carbon::now()->endOfWeek(),
                'participants' => 8
            ]
        ];
    }

    private function getCompletedChallenges(string $groupId): array
    {
        return [
            [
                'challenge_id' => 2,
                'challenge_type' => 'streak',
                'title' => 'Daily Streak Challenge',
                'description' => 'Workout 7 days in a row',
                'target_value' => 7,
                'completed_date' => Carbon::now()->subWeek(),
                'participants' => 5,
                'completion_rate' => 85
            ]
        ];
    }

    private function getGroupChallengeStats(string $groupId): array
    {
        return [
            'total_challenges_created' => 12,
            'active_challenges' => 1,
            'completed_challenges' => 11,
            'average_participation_rate' => 78.5,
            'most_popular_challenge_type' => 'workout_count'
        ];
    }
}