<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupMember;
use App\Services\AuthService;
use App\Events\GroupMemberUpdated;
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

                // Broadcast real-time member update when user rejoins
                $this->broadcastGroupMemberUpdate($group->group_id);

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

        // Broadcast real-time member update to all group members
        $this->broadcastGroupMemberUpdate($group->group_id);

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

                // Broadcast real-time member update when user rejoins
                $this->broadcastGroupMemberUpdate((int)$groupId);

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

        // Broadcast real-time member update to all group members
        $this->broadcastGroupMemberUpdate((int)$groupId);

        return response()->json([
            'status' => 'success',
            'data' => $membership->load(['group']),
            'message' => 'Joined group successfully'
        ], 201);
    }

    public function inviteUser(Request $request, string $groupId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required_without:username|integer',
            'username' => 'required_without:user_id|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed. Provide either user_id or username.',
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

        // Resolve username to user_id if username is provided
        $targetUserId = $request->user_id;
        if ($request->has('username')) {
            $authService = new AuthService();
            $token = $request->bearerToken();
            $userData = $authService->searchUserByUsername($token, $request->username);

            if (!$userData || !isset($userData['user_id'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found with username: ' . $request->username
                ], 404);
            }

            $targetUserId = $userData['user_id'];
        }

        $existingMembership = GroupMember::where('group_id', $groupId)
            ->where('user_id', $targetUserId)
            ->where('is_active', true)
            ->first();

        if ($existingMembership) {
            return response()->json([
                'status' => 'error',
                'message' => 'User is already a member'
            ], 400);
        }

        $this->notifyUserInvitation($group, $targetUserId, $request->attributes->get('user_id'));

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

        // Get group for notification
        $group = Group::where('group_id', $groupId)->first();

        // Send notification to the kicked user
        if ($group) {
            $this->notifyUserKicked($group, (int)$userId, (int)$request->attributes->get('user_id'));
        }

        // Broadcast real-time member update to all group members
        $this->broadcastGroupMemberUpdate((int)$groupId);

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
            $response = $client->post(env('COMMS_SERVICE_URL') . '/api/comms/group-invitation', [
                'json' => [
                    'type' => 'group_invitation',
                    'group_id' => $group->group_id,
                    'group_name' => $group->group_name,
                    'invited_user_id' => $invitedUserId,
                    'inviter_user_id' => $inviterUserId,
                    'group_code' => $group->group_code
                ]
            ]);

            \Log::info('Group invitation notification sent successfully', [
                'group_id' => $group->group_id,
                'invited_user_id' => $invitedUserId,
                'inviter_user_id' => $inviterUserId
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to notify user invitation: ' . $e->getMessage(), [
                'group_id' => $group->group_id,
                'invited_user_id' => $invitedUserId,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function notifyUserKicked(Group $group, int $kickedUserId, int $kickedByUserId): void
    {
        try {
            $client = new Client();
            $response = $client->post(env('COMMS_SERVICE_URL') . '/api/comms/group-member-kicked', [
                'json' => [
                    'kicked_user_id' => $kickedUserId,
                    'group_name' => $group->group_name,
                    'group_id' => $group->group_id,
                    'kicked_by_user_id' => $kickedByUserId,
                ]
            ]);

            \Log::info('Group member kicked notification sent successfully', [
                'group_id' => $group->group_id,
                'kicked_user_id' => $kickedUserId,
                'kicked_by_user_id' => $kickedByUserId
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to notify user kicked: ' . $e->getMessage(), [
                'group_id' => $group->group_id,
                'kicked_user_id' => $kickedUserId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Broadcast real-time group member list update via WebSocket
     */
    private function broadcastGroupMemberUpdate(int $groupId): void
    {
        try {
            // Fetch updated member count
            $memberCount = GroupMember::where('group_id', $groupId)
                ->where('is_active', true)
                ->count();

            // Fetch updated members list
            $groupMembers = GroupMember::where('group_id', $groupId)
                ->where('is_active', true)
                ->orderBy('joined_at', 'desc')
                ->get();

            // Batch fetch ALL usernames from auth service at once (more efficient)
            $userIds = $groupMembers->pluck('user_id')->toArray();
            $usernamesMap = $this->batchFetchUsernames($userIds);

            // Map members with fetched usernames
            $members = $groupMembers->map(function($member) use ($usernamesMap) {
                return [
                    'id' => $member->group_member_id, // Use correct primary key
                    'userId' => (string)$member->user_id,
                    'username' => $usernamesMap[$member->user_id] ?? 'User ' . $member->user_id,
                    'role' => $member->member_role,
                    'joinedAt' => $member->joined_at ? $member->joined_at->toISOString() : null,
                ];
            })->toArray();

            // Broadcast to group's private channel
            broadcast(new GroupMemberUpdated($groupId, $memberCount, $members));

            \Log::info('Group member update broadcasted', [
                'group_id' => $groupId,
                'member_count' => $memberCount,
                'members_broadcasted' => count($members),
                'usernames_fetched' => count($usernamesMap)
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to broadcast group member update', [
                'group_id' => $groupId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Batch fetch usernames for multiple users (MUCH more efficient than one-by-one)
     * Returns array mapping user_id => username
     */
    private function batchFetchUsernames(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        try {
            $client = new Client();

            // Call batch endpoint - Note: route is /api/batch-user-profiles (no /auth/)
            $response = $client->post(env('AUTH_SERVICE_URL') . '/api/batch-user-profiles', [
                'json' => ['user_ids' => $userIds],
                'timeout' => 5,
                'headers' => ['Accept' => 'application/json']
            ]);

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody(), true);
                $profiles = $data['data'] ?? [];

                // Create map of user_id => username
                $usernamesMap = [];
                foreach ($profiles as $profile) {
                    $userId = $profile['id'] ?? $profile['user_id'] ?? null;
                    $username = $profile['username'] ?? null;
                    if ($userId && $username) {
                        $usernamesMap[$userId] = $username;
                    }
                }

                \Log::info('Batch fetched usernames', [
                    'requested' => count($userIds),
                    'fetched' => count($usernamesMap)
                ]);

                return $usernamesMap;
            }
        } catch (\Exception $e) {
            \Log::error('Failed to batch fetch usernames', [
                'user_ids' => $userIds,
                'error' => $e->getMessage()
            ]);
        }

        // Fallback: If batch fetch fails, return empty array
        // Members will show as "User {id}"
        return [];
    }
}