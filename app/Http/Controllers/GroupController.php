<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupWorkoutEvaluation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use GuzzleHttp\Client;
use Carbon\Carbon;

class GroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Group::with(['activeMembers', 'creator'])
            ->active()
            ->public();

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
                'created_by' => Auth::id(),
                'max_members' => $request->max_members ?? 10,
                'is_private' => $request->is_private ?? false,
                'group_image' => $request->group_image
            ]);

            GroupMember::create([
                'group_id' => $group->group_id,
                'user_id' => Auth::id(),
                'member_role' => 'admin'
            ]);

            DB::commit();

            $this->notifyGroupCreation($group);

            return response()->json([
                'status' => 'success',
                'data' => $group->load(['activeMembers', 'creator']),
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

    public function show(string $groupId): JsonResponse
    {
        $group = Group::with(['activeMembers.user', 'creator', 'workoutEvaluations'])
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
                ->where('user_id', Auth::id())
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
        $groupData['user_membership'] = $this->getUserMembership($groupId, Auth::id());
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
            ->where('user_id', Auth::id())
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
            'data' => $group->fresh(['activeMembers', 'creator']),
            'message' => 'Group updated successfully'
        ]);
    }

    public function destroy(string $groupId): JsonResponse
    {
        $group = Group::where('group_id', $groupId)->active()->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group not found'
            ], 404);
        }

        $membership = GroupMember::where('group_id', $groupId)
            ->where('user_id', Auth::id())
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
        $userId = Auth::id();

        $userJoinedGroups = GroupMember::where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('group_id');

        $recommendedGroups = Group::with(['activeMembers', 'creator'])
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
                'created_by' => $group->creator,
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

        $query = Group::with(['activeMembers', 'creator'])
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

    public function getGroupActivity(string $groupId): JsonResponse
    {
        $group = Group::where('group_id', $groupId)->active()->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group not found'
            ], 404);
        }

        $membership = GroupMember::where('group_id', $groupId)
            ->where('user_id', Auth::id())
            ->where('is_active', true)
            ->first();

        if (!$membership && $group->is_private) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied to private group'
            ], 403);
        }

        $recentEvaluations = GroupWorkoutEvaluation::with(['user'])
            ->where('group_id', $groupId)
            ->recent(30)
            ->latest()
            ->limit(20)
            ->get();

        $recentMembers = GroupMember::with(['user'])
            ->where('group_id', $groupId)
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

    private function notifyGroupCreation(Group $group): void
    {
        try {
            $client = new Client();
            $client->post(env('COMMS_SERVICE_URL') . '/comms/group-notification', [
                'json' => [
                    'type' => 'group_created',
                    'group_id' => $group->group_id,
                    'group_name' => $group->group_name,
                    'created_by' => $group->created_by
                ]
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to notify group creation: ' . $e->getMessage());
        }
    }
}
