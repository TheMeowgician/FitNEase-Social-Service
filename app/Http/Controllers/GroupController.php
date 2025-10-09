<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupWorkoutEvaluation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Services\AuthService;
use App\Services\CommunicationsService;
use App\Events\GroupWorkoutInvitation;
use Carbon\Carbon;
use Illuminate\Support\Str;

class GroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Group::with(['activeMembers'])
            ->active();

        // If user_id is provided, show all groups the user is a member of (public and private)
        if ($request->has('user_id')) {
            $userId = $request->get('user_id');
            $query->whereHas('activeMembers', function($q) use ($userId) {
                $q->where('user_id', $userId);
            });
        } else {
            // Otherwise, only show public groups for discovery
            $query->public();
        }

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function($q) use ($search) {
                $q->where('group_name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('available_slots') && $request->get('available_slots')) {
            $query->withAvailableSlots();
        }

        $groups = $query->latest()
            ->paginate($request->get('per_page', 10));

        return response()->json([
            'status' => 'success',
            'data' => $groups,
            'message' => 'Groups retrieved successfully'
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'group_name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'max_members' => 'nullable|integer|min:2|max:50',
            'is_private' => 'nullable|boolean',
            'group_image' => 'nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $group = Group::create([
                'group_name' => $request->group_name,
                'description' => $request->description,
                'created_by' => $request->attributes->get('user_id'),
                'max_members' => $request->max_members ?? 10,
                'is_private' => $request->is_private ?? false,
                'group_image' => $request->group_image
            ]);

            GroupMember::create([
                'group_id' => $group->group_id,
                'user_id' => $request->attributes->get('user_id'),
                'member_role' => 'admin'
            ]);

            DB::commit();

            $this->notifyGroupCreation($request, $group);

            return response()->json([
                'status' => 'success',
                'data' => $group->load(['activeMembers']),
                'message' => 'Group created successfully'
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create group'
            ], 500);
        }
    }

    public function show(Request $request, string $groupId): JsonResponse
    {
        $group = Group::with(['activeMembers', 'workoutEvaluations'])
            ->where('group_id', $groupId)
            ->active()
            ->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group not found'
            ], 404);
        }

        if ($group->is_private) {
            $membership = GroupMember::where('group_id', $groupId)
                ->where('user_id', $request->attributes->get('user_id'))
                ->where('is_active', true)
                ->first();

            if (!$membership) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Access denied to private group'
                ], 403);
            }
        }

        $groupData = $group->toArray();
        $groupData['user_membership'] = $this->getUserMembership($groupId, $request->attributes->get('user_id'));
        $groupData['activity_level'] = $this->calculateGroupActivityLevel($groupId);

        return response()->json([
            'status' => 'success',
            'data' => $groupData,
            'message' => 'Group details retrieved successfully'
        ]);
    }

    public function update(Request $request, string $groupId): JsonResponse
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

        if (!$membership || !$membership->canManageGroup()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized to update group'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'group_name' => 'sometimes|string|max:100',
            'description' => 'sometimes|nullable|string',
            'max_members' => 'sometimes|integer|min:' . $group->current_member_count . '|max:50',
            'is_private' => 'sometimes|boolean',
            'group_image' => 'sometimes|nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $group->update($request->only([
            'group_name', 'description', 'max_members', 'is_private', 'group_image'
        ]));

        return response()->json([
            'status' => 'success',
            'data' => $group->fresh(['activeMembers']),
            'message' => 'Group updated successfully'
        ]);
    }

    public function destroy(Request $request, string $groupId): JsonResponse
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
            ->where('member_role', 'admin')
            ->where('is_active', true)
            ->first();

        if (!$membership) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only group admins can delete groups'
            ], 403);
        }

        $group->update(['is_active' => false]);

        return response()->json([
            'status' => 'success',
            'message' => 'Group deleted successfully'
        ]);
    }

    public function discoverGroups(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('user_id');

        $userJoinedGroups = GroupMember::where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('group_id');

        $recommendedGroups = Group::with(['activeMembers'])
            ->active()
            ->public()
            ->withAvailableSlots()
            ->whereNotIn('group_id', $userJoinedGroups)
            ->limit(10)
            ->get();

        $groupsWithActivity = $recommendedGroups->map(function($group) {
            return [
                'group_id' => $group->group_id,
                'group_name' => $group->group_name,
                'description' => $group->description,
                'member_count' => $group->current_member_count,
                'max_members' => $group->max_members,
                'activity_level' => $this->calculateGroupActivityLevel($group->group_id),
                'created_by' => $group->created_by,
                'group_image' => $group->group_image
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $groupsWithActivity,
            'message' => 'Recommended groups retrieved successfully'
        ]);
    }

    public function searchGroups(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:2',
            'activity_level' => 'sometimes|in:very_active,active,moderate,low',
            'max_members_range' => 'sometimes|array|size:2',
            'max_members_range.*' => 'integer|min:2|max:50'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $query = Group::with(['activeMembers'])
            ->active()
            ->public();

        $searchTerm = $request->get('query');
        $query->where(function($q) use ($searchTerm) {
            $q->where('group_name', 'like', "%{$searchTerm}%")
              ->orWhere('description', 'like', "%{$searchTerm}%");
        });

        if ($request->has('max_members_range')) {
            $range = $request->get('max_members_range');
            $query->whereBetween('max_members', [$range[0], $range[1]]);
        }

        $groups = $query->latest()->paginate($request->get('per_page', 10));

        return response()->json([
            'status' => 'success',
            'data' => $groups,
            'message' => 'Search results retrieved successfully'
        ]);
    }

    public function getGroupActivity(Request $request, string $groupId): JsonResponse
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

        $recentEvaluations = GroupWorkoutEvaluation::where('group_id', $groupId)
            ->recent(30)
            ->latest()
            ->limit(20)
            ->get();

        $recentMembers = GroupMember::where('group_id', $groupId)
            ->where('is_active', true)
            ->latest('joined_at')
            ->limit(5)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'recent_evaluations' => $recentEvaluations,
                'recent_members' => $recentMembers,
                'activity_stats' => [
                    'total_evaluations' => GroupWorkoutEvaluation::where('group_id', $groupId)->count(),
                    'weekly_evaluations' => GroupWorkoutEvaluation::where('group_id', $groupId)->recent(7)->count(),
                    'monthly_evaluations' => GroupWorkoutEvaluation::where('group_id', $groupId)->recent(30)->count()
                ]
            ],
            'message' => 'Group activity retrieved successfully'
        ]);
    }

    private function calculateGroupActivityLevel(string $groupId): string
    {
        $recentActivity = GroupWorkoutEvaluation::where('group_id', $groupId)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->count();

        return match(true) {
            $recentActivity >= 20 => 'very_active',
            $recentActivity >= 10 => 'active',
            $recentActivity >= 5 => 'moderate',
            default => 'low'
        };
    }

    private function getUserMembership(string $groupId, int $userId): ?array
    {
        $membership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->first();

        return $membership ? [
            'is_member' => true,
            'member_role' => $membership->member_role,
            'joined_at' => $membership->joined_at
        ] : ['is_member' => false];
    }

    private function notifyGroupCreation(Request $request, Group $group): void
    {
        $token = $request->bearerToken();
        if ($token) {
            $commsService = new CommunicationsService();
            $commsService->sendGroupNotification($token, [
                'type' => 'group_created',
                'group_id' => $group->group_id,
                'group_name' => $group->group_name,
                'created_by' => $group->created_by
            ]);
        }
    }

    public function initiateGroupWorkout(Request $request, string $groupId): JsonResponse
    {
        // Log incoming request data for debugging
        Log::info('🚪 Incoming Group Workout Request', [
            'group_id' => $groupId,
            'all_data' => $request->all(),
            'has_workout_data' => $request->has('workout_data'),
            'workout_data_raw' => $request->input('workout_data'),
            'workout_data_type' => gettype($request->input('workout_data')),
            'workout_format' => $request->input('workout_data.workout_format'),
            'exercises' => $request->input('workout_data.exercises'),
            'content_type' => $request->header('Content-Type'),
            'method' => $request->method()
        ]);

        $validator = Validator::make($request->all(), [
            'workout_data' => 'required|array',
            'workout_data.workout_format' => 'required|string',
            'workout_data.exercises' => 'present|array',  // Changed from 'required' to 'present' to allow empty arrays
        ]);

        if ($validator->fails()) {
            Log::error('❌ Validation Failed', [
                'errors' => $validator->errors()->toArray(),
                'failed_rules' => $validator->failed(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if group exists and user is a member
        $group = Group::where('group_id', $groupId)->active()->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group not found'
            ], 404);
        }

        $userId = $request->attributes->get('user_id');
        $membership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->first();

        if (!$membership) {
            return response()->json([
                'status' => 'error',
                'message' => 'You must be a member of this group to initiate a workout'
            ], 403);
        }

        // Get user info from auth service
        $authService = new AuthService();
        $userProfile = $authService->getUserProfile($request->bearerToken(), $userId);

        Log::info('User profile for initiator', [
            'user_id' => $userId,
            'profile_exists' => $userProfile !== null,
            'has_data' => isset($userProfile['data']),
            'profile_data' => $userProfile
        ]);

        // Try to get full name from profile
        $initiatorName = 'Unknown User';
        // AuthService returns data directly, not wrapped in 'data' key
        if ($userProfile) {
            $data = $userProfile;

            Log::info('Extracting initiator name', [
                'full_name' => $data['full_name'] ?? 'not set',
                'first_name' => $data['first_name'] ?? 'not set',
                'last_name' => $data['last_name'] ?? 'not set',
                'username' => $data['username'] ?? 'not set',
                'email' => $data['email'] ?? 'not set'
            ]);

            if (!empty($data['full_name'])) {
                $initiatorName = $data['full_name'];
            } elseif (!empty($data['first_name']) || !empty($data['last_name'])) {
                $initiatorName = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
            } elseif (!empty($data['username'])) {
                $initiatorName = $data['username'];
            } elseif (!empty($data['email'])) {
                $initiatorName = explode('@', $data['email'])[0];
            } else {
                $initiatorName = 'User ' . $userId;
            }
        }

        Log::info('Final initiator name', ['name' => $initiatorName]);

        // Generate unique session ID
        $sessionId = Str::uuid()->toString();

        // Don't auto-broadcast - let the initiator manually invite members
        Log::info('Lobby created, waiting for manual invitations', [
            'group_id' => $groupId,
            'initiator_id' => $userId,
            'session_id' => $sessionId
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'session_id' => $sessionId,
                'group_id' => $groupId,
                'initiator_id' => $userId,
                'workout_data' => $request->workout_data
            ],
            'message' => 'Lobby created successfully. Use invite button to invite members.'
        ]);
    }

    /**
     * Update member status in workout lobby
     */
    public function updateLobbyStatus(Request $request, string $sessionId): JsonResponse
    {
        $userId = $request->attributes->get('user_id');

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:waiting,ready',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        // Get user info
        $authService = new AuthService();
        $userProfile = $authService->getUserProfile($request->bearerToken(), $userId);

        $userName = 'Unknown User';
        if ($userProfile) {
            if (!empty($userProfile['full_name'])) {
                $userName = $userProfile['full_name'];
            } elseif (!empty($userProfile['first_name']) || !empty($userProfile['last_name'])) {
                $userName = trim(($userProfile['first_name'] ?? '') . ' ' . ($userProfile['last_name'] ?? ''));
            } elseif (!empty($userProfile['username'])) {
                $userName = $userProfile['username'];
            } elseif (!empty($userProfile['email'])) {
                $userName = explode('@', $userProfile['email'])[0];
            }
        }

        Log::info('Broadcasting member status update', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'name' => $userName,
            'status' => $request->status
        ]);

        // Broadcast status update to lobby
        broadcast(new \App\Events\MemberStatusUpdate(
            $sessionId,
            $userId,
            $userName,
            $request->status
        ));

        return response()->json([
            'status' => 'success',
            'message' => 'Status updated successfully'
        ]);
    }

    /**
     * Start workout for all members in lobby
     */
    public function startWorkout(Request $request, string $sessionId): JsonResponse
    {
        $userId = $request->attributes->get('user_id');

        Log::info('Starting workout for all members', [
            'session_id' => $sessionId,
            'initiator_id' => $userId,
            'start_time' => time()
        ]);

        // Broadcast workout start to all members in lobby
        broadcast(new \App\Events\WorkoutStarted(
            $sessionId,
            time()
        ));

        return response()->json([
            'status' => 'success',
            'data' => [
                'session_id' => $sessionId,
                'start_time' => time()
            ],
            'message' => 'Workout started for all members'
        ]);
    }

    /**
     * Pause workout for all members in session
     */
    public function pauseWorkout(Request $request, string $sessionId): JsonResponse
    {
        $userId = $request->attributes->get('user_id');

        // Get user info
        $authService = new AuthService();
        $userProfile = $authService->getUserProfile($request->bearerToken(), $userId);

        $userName = 'Unknown User';
        if ($userProfile) {
            if (!empty($userProfile['full_name'])) {
                $userName = $userProfile['full_name'];
            } elseif (!empty($userProfile['first_name']) || !empty($userProfile['last_name'])) {
                $userName = trim(($userProfile['first_name'] ?? '') . ' ' . ($userProfile['last_name'] ?? ''));
            } elseif (!empty($userProfile['username'])) {
                $userName = $userProfile['username'];
            } elseif (!empty($userProfile['email'])) {
                $userName = explode('@', $userProfile['email'])[0];
            }
        }

        Log::info('Pausing workout for all members', [
            'session_id' => $sessionId,
            'paused_by' => $userId,
            'paused_by_name' => $userName,
            'paused_at' => time()
        ]);

        // Broadcast workout pause to all members in session
        broadcast(new \App\Events\WorkoutPaused(
            $sessionId,
            $userId,
            $userName,
            time()
        ));

        return response()->json([
            'status' => 'success',
            'data' => [
                'session_id' => $sessionId,
                'paused_at' => time()
            ],
            'message' => 'Workout paused for all members'
        ]);
    }

    /**
     * Resume workout for all members in session
     */
    public function resumeWorkout(Request $request, string $sessionId): JsonResponse
    {
        $userId = $request->attributes->get('user_id');

        // Get user info
        $authService = new AuthService();
        $userProfile = $authService->getUserProfile($request->bearerToken(), $userId);

        $userName = 'Unknown User';
        if ($userProfile) {
            if (!empty($userProfile['full_name'])) {
                $userName = $userProfile['full_name'];
            } elseif (!empty($userProfile['first_name']) || !empty($userProfile['last_name'])) {
                $userName = trim(($userProfile['first_name'] ?? '') . ' ' . ($userProfile['last_name'] ?? ''));
            } elseif (!empty($userProfile['username'])) {
                $userName = $userProfile['username'];
            } elseif (!empty($userProfile['email'])) {
                $userName = explode('@', $userProfile['email'])[0];
            }
        }

        Log::info('Resuming workout for all members', [
            'session_id' => $sessionId,
            'resumed_by' => $userId,
            'resumed_by_name' => $userName,
            'resumed_at' => time()
        ]);

        // Broadcast workout resume to all members in session
        broadcast(new \App\Events\WorkoutResumed(
            $sessionId,
            $userId,
            $userName,
            time()
        ));

        return response()->json([
            'status' => 'success',
            'data' => [
                'session_id' => $sessionId,
                'resumed_at' => time()
            ],
            'message' => 'Workout resumed for all members'
        ]);
    }

    /**
     * Stop workout for all members in session
     */
    public function stopWorkout(Request $request, string $sessionId): JsonResponse
    {
        $userId = $request->attributes->get('user_id');

        // Get user info
        $authService = new AuthService();
        $userProfile = $authService->getUserProfile($request->bearerToken(), $userId);

        $userName = 'Unknown User';
        if ($userProfile) {
            if (!empty($userProfile['full_name'])) {
                $userName = $userProfile['full_name'];
            } elseif (!empty($userProfile['first_name']) || !empty($userProfile['last_name'])) {
                $userName = trim(($userProfile['first_name'] ?? '') . ' ' . ($userProfile['last_name'] ?? ''));
            } elseif (!empty($userProfile['username'])) {
                $userName = $userProfile['username'];
            } elseif (!empty($userProfile['email'])) {
                $userName = explode('@', $userProfile['email'])[0];
            }
        }

        Log::info('Stopping workout for all members', [
            'session_id' => $sessionId,
            'stopped_by' => $userId,
            'stopped_by_name' => $userName,
            'stopped_at' => time()
        ]);

        // Broadcast workout stop to all members in session
        broadcast(new \App\Events\WorkoutStopped(
            $sessionId,
            $userId,
            $userName,
            time()
        ));

        return response()->json([
            'status' => 'success',
            'data' => [
                'session_id' => $sessionId,
                'stopped_at' => time()
            ],
            'message' => 'Workout stopped for all members'
        ]);
    }

    /**
     * Resend lobby invitation to specific member
     */
    public function inviteMemberToLobby(Request $request, string $sessionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'target_user_id' => 'required|integer',
            'group_id' => 'required|integer',
            'workout_data' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $initiatorId = $request->attributes->get('user_id');
        $targetUserId = $request->input('target_user_id');
        $groupId = $request->input('group_id');
        $workoutData = $request->input('workout_data');

        // Get initiator info
        $authService = new AuthService();
        $initiatorProfile = $authService->getUserProfile($request->bearerToken(), $initiatorId);

        $initiatorName = 'Unknown User';
        if ($initiatorProfile) {
            if (!empty($initiatorProfile['full_name'])) {
                $initiatorName = $initiatorProfile['full_name'];
            } elseif (!empty($initiatorProfile['first_name']) || !empty($initiatorProfile['last_name'])) {
                $initiatorName = trim(($initiatorProfile['first_name'] ?? '') . ' ' . ($initiatorProfile['last_name'] ?? ''));
            } elseif (!empty($initiatorProfile['username'])) {
                $initiatorName = $initiatorProfile['username'];
            }
        }

        Log::info('Sending lobby invitation to specific member', [
            'session_id' => $sessionId,
            'group_id' => $groupId,
            'initiator_id' => $initiatorId,
            'initiator_name' => $initiatorName,
            'target_user_id' => $targetUserId
        ]);

        // Log before broadcast
        Log::info('About to broadcast lobby invitation', [
            'group_id' => $groupId,
            'initiator_id' => $initiatorId,
            'session_id' => $sessionId,
            'channel' => "private-group.{$groupId}"
        ]);

        // Broadcast invitation to the group channel (the user should be subscribed)
        broadcast(new GroupWorkoutInvitation(
            (int) $groupId,
            $initiatorId,
            $initiatorName,
            $workoutData,
            $sessionId
        ));

        Log::info('Broadcast call completed for lobby invitation', [
            'session_id' => $sessionId
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Lobby invitation sent successfully'
        ]);
    }

    /**
     * Broadcast generated exercises to all lobby members
     */
    public function broadcastExercises(Request $request, string $sessionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'workout_data' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = $request->attributes->get('user_id');

        Log::info('Broadcasting generated exercises to lobby', [
            'session_id' => $sessionId,
            'initiator_id' => $userId,
            'exercises_count' => count($request->workout_data['exercises'] ?? [])
        ]);

        // Broadcast exercises to all members in the lobby
        broadcast(new \App\Events\ExercisesGenerated(
            $sessionId,
            $request->workout_data
        ));

        return response()->json([
            'status' => 'success',
            'message' => 'Exercises broadcast to all lobby members'
        ]);
    }

    /**
     * Send chat message in lobby
     */
    public function sendLobbyMessage(Request $request, string $sessionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = $request->attributes->get('user_id');
        $message = $request->input('message');

        // Get user info
        $authService = new AuthService();
        $userProfile = $authService->getUserProfile($request->bearerToken(), $userId);

        $userName = 'Unknown User';
        if ($userProfile) {
            // Prioritize username for chat display
            if (!empty($userProfile['username'])) {
                $userName = $userProfile['username'];
            } elseif (!empty($userProfile['email'])) {
                $userName = explode('@', $userProfile['email'])[0];
            } elseif (!empty($userProfile['full_name'])) {
                $userName = $userProfile['full_name'];
            } elseif (!empty($userProfile['first_name']) || !empty($userProfile['last_name'])) {
                $userName = trim(($userProfile['first_name'] ?? '') . ' ' . ($userProfile['last_name'] ?? ''));
            }
        }

        // Generate unique message ID
        $messageId = Str::uuid()->toString();
        $timestamp = time();

        Log::info('Broadcasting lobby chat message', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'user_name' => $userName,
            'message_id' => $messageId,
            'message_length' => strlen($message)
        ]);

        // Broadcast message to all lobby members
        broadcast(new \App\Events\LobbyMessageSent(
            $sessionId,
            $userId,
            $userName,
            $message,
            $timestamp,
            $messageId
        ));

        return response()->json([
            'status' => 'success',
            'data' => [
                'message_id' => $messageId,
                'timestamp' => $timestamp
            ],
            'message' => 'Message sent successfully'
        ]);
    }

    /**
     * Pass initiator role to another member in the lobby
     */
    public function passInitiatorRole(Request $request, string $sessionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'new_initiator_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $currentInitiatorId = $request->attributes->get('user_id');
        $newInitiatorId = $request->input('new_initiator_id');

        // Get current initiator info
        $authService = new AuthService();
        $currentInitiatorProfile = $authService->getUserProfile($request->bearerToken(), $currentInitiatorId);

        $currentInitiatorName = 'Unknown User';
        if ($currentInitiatorProfile) {
            if (!empty($currentInitiatorProfile['username'])) {
                $currentInitiatorName = $currentInitiatorProfile['username'];
            } elseif (!empty($currentInitiatorProfile['full_name'])) {
                $currentInitiatorName = $currentInitiatorProfile['full_name'];
            } elseif (!empty($currentInitiatorProfile['email'])) {
                $currentInitiatorName = explode('@', $currentInitiatorProfile['email'])[0];
            }
        }

        // Get new initiator info
        $newInitiatorProfile = $authService->getUserProfile($request->bearerToken(), $newInitiatorId);

        $newInitiatorName = 'Unknown User';
        if ($newInitiatorProfile) {
            if (!empty($newInitiatorProfile['username'])) {
                $newInitiatorName = $newInitiatorProfile['username'];
            } elseif (!empty($newInitiatorProfile['full_name'])) {
                $newInitiatorName = $newInitiatorProfile['full_name'];
            } elseif (!empty($newInitiatorProfile['email'])) {
                $newInitiatorName = explode('@', $newInitiatorProfile['email'])[0];
            }
        }

        Log::info('Passing initiator role in lobby', [
            'session_id' => $sessionId,
            'previous_initiator_id' => $currentInitiatorId,
            'previous_initiator_name' => $currentInitiatorName,
            'new_initiator_id' => $newInitiatorId,
            'new_initiator_name' => $newInitiatorName
        ]);

        // Broadcast role transfer to all lobby members
        broadcast(new \App\Events\PassInitiatorRole(
            $sessionId,
            $newInitiatorId,
            $newInitiatorName,
            $currentInitiatorId,
            $currentInitiatorName
        ));

        return response()->json([
            'status' => 'success',
            'data' => [
                'new_initiator_id' => $newInitiatorId,
                'new_initiator_name' => $newInitiatorName
            ],
            'message' => 'Initiator role transferred successfully'
        ]);
    }
}
