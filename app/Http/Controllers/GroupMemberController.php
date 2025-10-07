<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupMember;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use GuzzleHttp\Client;

class GroupMemberController extends Controller
{
    public function joinGroupWithCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'group_code' => 'required|string|size:8'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $group = Group::where('group_code', $request->group_code)
            ->active()
            ->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid group code'
            ], 404);
        }

        if ($group->current_member_count >= $group->max_members) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group is full'
            ], 400);
        }

        $existingMembership = GroupMember::where('group_id', $group->group_id)
            ->where('user_id', $request->attributes->get('user_id'))
            ->first();

        if ($existingMembership) {
            if ($existingMembership->is_active) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Already a member of this group'
                ], 400);
            } else {
                $existingMembership->update(['is_active' => true, 'joined_at' => now()]);

                return response()->json([
                    'status' => 'success',
                    'data' => $existingMembership->load(['group']),
                    'message' => 'Rejoined group successfully'
                ]);
            }
        }

        $membership = GroupMember::create([
            'group_id' => $group->group_id,
            'user_id' => $request->attributes->get('user_id'),
            'member_role' => 'member'
        ]);

        $this->notifyGroupJoin($group, $request->attributes->get('user_id'));

        return response()->json([
            'status' => 'success',
            'data' => $membership->load(['group']),
            'message' => 'Joined group successfully'
        ], 201);
    }

    public function joinGroup(Request $request, string $groupId): JsonResponse
    {
        $group = Group::where('group_id', $groupId)
            ->active()
            ->public()
            ->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group not found or is private'
            ], 404);
        }

        if ($group->current_member_count >= $group->max_members) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group is full'
            ], 400);
        }

        $existingMembership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $request->attributes->get('user_id'))
            ->first();

        if ($existingMembership) {
            if ($existingMembership->is_active) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Already a member of this group'
                ], 400);
            } else {
                $existingMembership->update(['is_active' => true, 'joined_at' => now()]);

                return response()->json([
                    'status' => 'success',
                    'data' => $existingMembership->load(['group']),
                    'message' => 'Rejoined group successfully'
                ]);
            }
        }

        $membership = GroupMember::create([
            'group_id' => $groupId,
            'user_id' => $request->attributes->get('user_id'),
            'member_role' => 'member'
        ]);

        $this->notifyGroupJoin($group, $request->attributes->get('user_id'));

        return response()->json([
            'status' => 'success',
            'data' => $membership->load(['group']),
            'message' => 'Joined group successfully'
        ], 201);
    }

    public function inviteUser(Request $request, string $groupId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $group = Group::where('group_id', $groupId)->active()->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group not found'
            ], 404);
        }

        $inviterMembership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $request->attributes->get('user_id'))
            ->where('is_active', true)
            ->first();

        if (!$inviterMembership || !$inviterMembership->canManageGroup()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized to invite users'
            ], 403);
        }

        if ($group->current_member_count >= $group->max_members) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group is full'
            ], 400);
        }

        $existingMembership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $request->user_id)
            ->where('is_active', true)
            ->first();

        if ($existingMembership) {
            return response()->json([
                'status' => 'error',
                'message' => 'User is already a member'
            ], 400);
        }

        $this->notifyUserInvitation($group, $request->user_id, $request->attributes->get('user_id'));

        return response()->json([
            'status' => 'success',
            'message' => 'Invitation sent successfully'
        ]);
    }

    public function leaveGroup(Request $request, string $groupId): JsonResponse
    {
        $membership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $request->attributes->get('user_id'))
            ->where('is_active', true)
            ->first();

        if (!$membership) {
            return response()->json([
                'status' => 'error',
                'message' => 'Not a member of this group'
            ], 400);
        }

        if ($membership->member_role === 'admin') {
            $otherAdmins = GroupMember::where('group_id', $groupId)
                ->where('member_role', 'admin')
                ->where('user_id', '!=', $request->attributes->get('user_id'))
                ->where('is_active', true)
                ->count();

            if ($otherAdmins === 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot leave group as the only admin. Transfer admin role first or delete the group.'
                ], 400);
            }
        }

        $membership->update(['is_active' => false]);

        return response()->json([
            'status' => 'success',
            'message' => 'Left group successfully'
        ]);
    }

    public function getUserGroups(Request $request, string $userId): JsonResponse
    {
        if ($request->attributes->get('user_id') != $userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized to view user groups'
            ], 403);
        }

        $memberships = GroupMember::with(['group'])
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->latest('joined_at')
            ->get();

        $groupsData = $memberships->map(function($membership) {
            $group = $membership->group;
            return [
                'group_id' => $group->group_id,
                'group_name' => $group->group_name,
                'description' => $group->description,
                'member_count' => $group->current_member_count,
                'max_members' => $group->max_members,
                'member_role' => $membership->member_role,
                'joined_at' => $membership->joined_at,
                'group_image' => $group->group_image,
                'is_private' => $group->is_private,
                'created_by' => $group->created_by
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $groupsData,
            'message' => 'User groups retrieved successfully'
        ]);
    }

    public function updateMemberRole(Request $request, string $groupId, string $userId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'member_role' => 'required|in:admin,moderator,member'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $updaterMembership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $request->attributes->get('user_id'))
            ->where('member_role', 'admin')
            ->where('is_active', true)
            ->first();

        if (!$updaterMembership) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only admins can update member roles'
            ], 403);
        }

        $targetMembership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->first();

        if (!$targetMembership) {
            return response()->json([
                'status' => 'error',
                'message' => 'Member not found'
            ], 404);
        }

        if ($userId == $request->attributes->get('user_id')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot change your own role'
            ], 400);
        }

        $targetMembership->update(['member_role' => $request->member_role]);

        return response()->json([
            'status' => 'success',
            'data' => $targetMembership->fresh(['group']),
            'message' => 'Member role updated successfully'
        ]);
    }

    public function removeMember(Request $request, string $groupId, string $userId): JsonResponse
    {
        $removerMembership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $request->attributes->get('user_id'))
            ->where('is_active', true)
            ->first();

        if (!$removerMembership || !$removerMembership->canManageGroup()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized to remove members'
            ], 403);
        }

        $targetMembership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->first();

        if (!$targetMembership) {
            return response()->json([
                'status' => 'error',
                'message' => 'Member not found'
            ], 404);
        }

        if ($userId == $request->attributes->get('user_id')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot remove yourself. Use leave group instead.'
            ], 400);
        }

        if ($targetMembership->member_role === 'admin' && $removerMembership->member_role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot remove admin members'
            ], 403);
        }

        $targetMembership->update(['is_active' => false]);

        return response()->json([
            'status' => 'success',
            'message' => 'Member removed successfully'
        ]);
    }

    public function getGroupMembers(string $groupId, Request $request): JsonResponse
    {
        $group = Group::where('group_id', $groupId)->active()->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group not found'
            ], 404);
        }

        $userMembership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $request->attributes->get('user_id'))
            ->where('is_active', true)
            ->first();

        if (!$userMembership && $group->is_private) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied to private group'
            ], 403);
        }

        $query = GroupMember::where('group_id', $groupId)
            ->where('is_active', true);

        if ($request->has('role')) {
            $query->where('member_role', $request->get('role'));
        }

        $members = $query->latest('joined_at')
            ->paginate($request->get('per_page', 20));

        // Enrich members with user details from auth service
        $authService = new AuthService();
        $token = $request->bearerToken();

        $membersWithUserDetails = $members->getCollection()->map(function($member) use ($authService, $token) {
            $userProfile = $authService->getUserProfile($token, $member->user_id);

            if ($userProfile) {
                $member->username = $userProfile['username'] ?? "User {$member->user_id}";
                $member->first_name = $userProfile['first_name'] ?? null;
                $member->last_name = $userProfile['last_name'] ?? null;
                $member->profile_picture = $userProfile['profile_picture'] ?? null;
            } else {
                // If auth service fails, use user_id as username
                $member->username = "User {$member->user_id}";
                $member->profile_picture = null;
            }

            return $member;
        });

        $members->setCollection($membersWithUserDetails);

        return response()->json([
            'status' => 'success',
            'data' => $members,
            'message' => 'Group members retrieved successfully'
        ]);
    }

    private function notifyGroupJoin(Group $group, int $userId): void
    {
        try {
            $client = new Client();
            $client->post(env('COMMS_SERVICE_URL') . '/comms/group-notification', [
                'json' => [
                    'type' => 'member_joined',
                    'group_id' => $group->group_id,
                    'group_name' => $group->group_name,
                    'user_id' => $userId
                ]
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to notify group join: ' . $e->getMessage());
        }
    }

    private function notifyUserInvitation(Group $group, int $invitedUserId, int $inviterUserId): void
    {
        try {
            $client = new Client();
            $client->post(env('COMMS_SERVICE_URL') . '/comms/group-invitation', [
                'json' => [
                    'type' => 'group_invitation',
                    'group_id' => $group->group_id,
                    'group_name' => $group->group_name,
                    'invited_user_id' => $invitedUserId,
                    'inviter_user_id' => $inviterUserId,
                    'group_code' => $group->group_code
                ]
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to notify user invitation: ' . $e->getMessage());
        }
    }
}